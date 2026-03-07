<?php
/**
 * Локальный вывод LTC-адреса из Ltub (BIP32) в рамках плагина, без вызова main.py.
 * Самодостаточная реализация на чистом PHP (bcmath или gmp для больших чисел).
 */

defined('ABSPATH') || exit;

class WC_Exchange_Quote_LTC_HD_Deriver {

    /**
     * Вывести один LTC-адрес по расширенному публичному ключу (Ltub) и индексу.
     * Возвращает адрес (строка) или пустую строку при ошибке.
     *
     * @param string $xpub Ltub или xpub для LTC
     * @param int    $index индекс вывода (0, 1, 2, ...)
     * @return string
     */
    public static function derive($xpub, $index) {
        $xpub = trim($xpub);
        if ($xpub === '' || $index < 0) {
            return '';
        }
        if (!extension_loaded('bcmath') && !extension_loaded('gmp')) {
            return '';
        }
        $decoded = self::base58_decode_check($xpub);
        // BIP32: 82 байта (4 version + 78 payload) или 78 байт у некоторых кошельков
        if ($decoded === null || strlen($decoded) < 78) {
            return '';
        }
        $chain_code = substr($decoded, 13, 32);
        $pub_key = substr($decoded, 45, 33);
        if (strlen($pub_key) !== 33 || $pub_key[0] !== "\x02" && $pub_key[0] !== "\x03") {
            return '';
        }
        $child = self::derive_child_public($pub_key, $chain_code, $index);
        if ($child === null) {
            return '';
        }
        return self::pubkey_to_ltc_address($child);
    }

    /**
     * BIP32 public child derivation: child_pub = parent_pub + (I_L * G).
     */
    private static function derive_child_public($parent_pub, $chain_code, $index) {
        $data = $parent_pub . pack('N', $index);
        $I = hash_hmac('sha512', $data, $chain_code, true);
        $I_L = substr($I, 0, 32);
        $I_R = substr($I, 32, 32);
        $I_L_int = self::bin_to_bigint($I_L);
        if (self::cmp_bigint($I_L_int, self::secp256k1_n()) >= 0) {
            return null;
        }
        $point_G = self::secp256k1_G();
        $point_IL_G = self::point_multiply($point_G, $I_L_int);
        $point_parent = self::pubkey_to_point($parent_pub);
        if ($point_parent === null) {
            return null;
        }
        $point_child = self::point_add($point_parent, $point_IL_G);
        if ($point_child === null) {
            return null;
        }
        return self::point_to_pubkey($point_child);
    }

    private static function pubkey_to_ltc_address($pubkey_bin) {
        if (strlen($pubkey_bin) !== 33) {
            return '';
        }
        $hash160 = self::hash160($pubkey_bin);
        if ($hash160 === null) {
            return '';
        }
        return self::base58_encode_check("\x30" . $hash160);
    }

    private static function hash160($data) {
        if (!function_exists('hash')) {
            return null;
        }
        $sha = hash('sha256', $data, true);
        if ($sha === false) {
            return null;
        }
        $ripe = @hash('ripemd160', $sha, true);
        return $ripe !== false ? $ripe : null;
    }

    private static function base58_decode_check($input) {
        $ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $dec = '0';
        for ($i = 0; $i < strlen($input); $i++) {
            $p = strpos($ALPHABET, $input[$i]);
            if ($p === false) {
                return null;
            }
            $dec = self::bigint_add(self::bigint_mul($dec, '58'), (string) $p);
        }
        $hex = self::bigint_to_hex($dec);
        if (strlen($hex) % 2) {
            $hex = '0' . $hex;
        }
        $bin = hex2bin($hex);
        if ($bin === false || strlen($bin) < 5) {
            return null;
        }
        $payload = substr($bin, 0, -4);
        $checksum = substr($bin, -4);
        $expected = substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);
        if ($expected !== $checksum) {
            return null;
        }
        return $payload;
    }

    private static function base58_encode_check($payload) {
        $ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $checksum = substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);
        $hex = bin2hex($payload . $checksum);
        $dec = self::hex_to_bigint($hex);
        $out = '';
        while (self::cmp_bigint($dec, '0') > 0) {
            list($dec, $r) = self::bigint_div_mod($dec, '58');
            $out = $ALPHABET[(int) $r] . $out;
        }
        for ($i = 0; $i < strlen($payload); $i++) {
            if ($payload[$i] !== "\x00") {
                break;
            }
            $out = '1' . $out;
        }
        return $out;
    }

    private static function secp256k1_p() {
        return '115792089237316195423570985008687907853269984665640564039457584007908834671663';
    }

    private static function secp256k1_n() {
        return '115792089237316195423570985008687907852837564279074904382605163141518161494337';
    }

    private static function secp256k1_G() {
        $gx = '55066263022277343669578718895168534326250603453777594175500187360389116729240';
        $gy = '32670510020758816978083085130507043184471273380659243275938904335757337482424';
        return array($gx, $gy);
    }

    private static function pubkey_to_point($pubkey) {
        if (strlen($pubkey) !== 33) {
            return null;
        }
        $x = self::bin_to_bigint(substr($pubkey, 1, 32));
        $y = self::secp256k1_y_from_x($x, $pubkey[0] === "\x03");
        return $y === null ? null : array($x, $y);
    }

    private static function secp256k1_y_from_x($x, $odd) {
        $p = self::secp256k1_p();
        $y2 = self::bigint_mod(self::bigint_add(self::bigint_mul(self::bigint_mul($x, $x), $x), '7'), $p);
        $y = self::bigint_sqrt_mod($y2, $p);
        if ($y === null) {
            return null;
        }
        $y_odd = (self::bigint_mod($y, '2') !== '0');
        if ($y_odd !== $odd) {
            $y = self::bigint_sub($p, $y);
        }
        return $y;
    }

    private static function point_to_pubkey($point) {
        $x = $point[0];
        $y = $point[1];
        $x_bin = self::bigint_to_bin($x, 32);
        if ($x_bin === null) {
            return null;
        }
        $prefix = (self::bigint_mod($y, '2') === '0') ? "\x02" : "\x03";
        return $prefix . $x_bin;
    }

    private static function point_add($P, $Q) {
        $p = self::secp256k1_p();
        if (self::is_infinity($P)) {
            return $Q;
        }
        if (self::is_infinity($Q)) {
            return $P;
        }
        if ($P[0] === $Q[0] && $P[1] === $Q[1]) {
            return self::point_double($P);
        }
        if ($P[0] === $Q[0]) {
            return self::secp256k1_infinity();
        }
        $dx = self::bigint_sub($Q[0], $P[0]);
        $dy = self::bigint_sub($Q[1], $P[1]);
        $dx = self::bigint_mod($dx, $p);
        $dy = self::bigint_mod($dy, $p);
        if ($dx === '0') {
            return null;
        }
        $inv_dx = self::bigint_invert($dx, $p);
        $s = self::bigint_mod(self::bigint_mul($dy, $inv_dx), $p);
        $s2 = self::bigint_mul($s, $s);
        $x3 = self::bigint_sub(self::bigint_sub(self::bigint_sub($s2, $P[0]), $Q[0]), $p);
        $x3 = self::bigint_mod($x3, $p);
        $y3 = self::bigint_sub(self::bigint_mul($s, self::bigint_sub($P[0], $x3)), $P[1]);
        $y3 = self::bigint_mod($y3, $p);
        return array($x3, $y3);
    }

    private static function point_double($P) {
        $p = self::secp256k1_p();
        $x = $P[0];
        $y = $P[1];
        if ($y === '0') {
            return null;
        }
        $y2 = self::bigint_mul($y, $y);
        $y2 = self::bigint_mod($y2, $p);
        $inv_2y = self::bigint_invert(self::bigint_mul($y, '2'), $p);
        // secp256k1: a=0, slope = 3x² / (2y). Параметр b=7 НЕ участвует в формуле удвоения.
        $three_x2 = self::bigint_mul(self::bigint_mul($x, $x), '3');
        $s = self::bigint_mod(self::bigint_mul($three_x2, $inv_2y), $p);
        $s2 = self::bigint_mul($s, $s);
        $x3 = self::bigint_mod(self::bigint_sub(self::bigint_sub($s2, self::bigint_mul($x, '2')), $p), $p);
        $y3 = self::bigint_mod(self::bigint_sub(self::bigint_mul($s, self::bigint_sub($x, $x3)), $y), $p);
        return array($x3, $y3);
    }

    private static function point_multiply($point, $n) {
        $p = self::secp256k1_p();
        $n = self::bigint_mod($n, self::secp256k1_n());
        if ($n === '0') {
            return self::secp256k1_infinity();
        }
        $R = null;
        $Q = $point;
        while (self::cmp_bigint($n, '0') > 0) {
            if (self::bigint_mod($n, '2') === '1') {
                $R = $R === null ? $Q : self::point_add($R, $Q);
            }
            $Q = self::point_double($Q);
            $n = self::bigint_div($n, '2');
        }
        return $R;
    }

    private static function secp256k1_infinity() {
        return array('0', '0');
    }

    private static function is_infinity($P) {
        return $P[0] === '0' && $P[1] === '0';
    }

    private static function bigint_sqrt_mod($a, $p) {
        if (extension_loaded('gmp')) {
            $a_g = gmp_init($a, 10);
            $p_g = gmp_init($p, 10);
            $r = gmp_powm($a_g, gmp_div_q(gmp_add($p_g, gmp_init(1, 10)), gmp_init(4, 10)), $p_g);
            return gmp_strval($r, 10);
        }
        $p_bc = $p;
        $a_bc = $a;
        $exp = bcadd($p_bc, '1', 0);
        $exp = bcdiv($exp, '4', 0);
        $r = bcpowmod($a_bc, $exp, $p_bc, 0);
        return $r;
    }

    private static function bin_to_bigint($bin) {
        $hex = bin2hex($bin);
        return self::hex_to_bigint($hex);
    }

    private static function bigint_to_bin($n, $bytes) {
        $hex = self::bigint_to_hex($n);
        if (strlen($hex) % 2) {
            $hex = '0' . $hex;
        }
        if (strlen($hex) / 2 > $bytes) {
            return null;
        }
        return str_pad(hex2bin($hex), $bytes, "\x00", STR_PAD_LEFT);
    }

    private static function hex_to_bigint($hex) {
        $hex = ltrim($hex, '0');
        if ($hex === '') {
            return '0';
        }
        if (extension_loaded('gmp')) {
            return gmp_strval(gmp_init($hex, 16), 10);
        }
        return base_convert($hex, 16, 10);
    }

    private static function bigint_to_hex($n) {
        if (extension_loaded('gmp')) {
            return gmp_strval(gmp_init($n, 10), 16);
        }
        return base_convert($n, 10, 16);
    }

    private static function cmp_bigint($a, $b) {
        if (extension_loaded('gmp')) {
            return gmp_cmp(gmp_init($a, 10), gmp_init($b, 10));
        }
        return bccomp($a, $b, 0);
    }

    private static function bigint_add($a, $b) {
        if (extension_loaded('gmp')) {
            return gmp_strval(gmp_add(gmp_init($a, 10), gmp_init($b, 10)), 10);
        }
        return bcadd($a, $b, 0);
    }

    private static function bigint_sub($a, $b) {
        if (extension_loaded('gmp')) {
            return gmp_strval(gmp_sub(gmp_init($a, 10), gmp_init($b, 10)), 10);
        }
        return bcsub($a, $b, 0);
    }

    private static function bigint_mul($a, $b) {
        if (extension_loaded('gmp')) {
            return gmp_strval(gmp_mul(gmp_init($a, 10), gmp_init($b, 10)), 10);
        }
        return bcmul($a, $b, 0);
    }

    private static function bigint_mod($a, $b) {
        if (extension_loaded('gmp')) {
            return gmp_strval(gmp_mod(gmp_init($a, 10), gmp_init($b, 10)), 10);
        }
        return bcmod($a, $b, 0);
    }

    private static function bigint_div($a, $b) {
        if (extension_loaded('gmp')) {
            return gmp_strval(gmp_div_q(gmp_init($a, 10), gmp_init($b, 10)), 10);
        }
        return bcdiv($a, $b, 0);
    }

    private static function bigint_div_mod($a, $b) {
        if (extension_loaded('gmp')) {
            $q = gmp_div_q(gmp_init($a, 10), gmp_init($b, 10));
            $r = gmp_mod(gmp_init($a, 10), gmp_init($b, 10));
            return array(gmp_strval($q, 10), gmp_strval($r, 10));
        }
        $q = bcdiv($a, $b, 0);
        $r = bcmod($a, $b, 0);
        return array($q, $r);
    }

    private static function bigint_invert($a, $m) {
        if (extension_loaded('gmp')) {
            $r = gmp_invert(gmp_init($a, 10), gmp_init($m, 10));
            return $r === false ? '0' : gmp_strval($r, 10);
        }
        $a = bcmod($a, $m);
        return bcpowmod($a, bcsub($m, '2', 0), $m, 0);
    }
}
