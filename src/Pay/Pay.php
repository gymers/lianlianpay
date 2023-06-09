<?php

namespace Gymers\LianlianPay\Pay;

class Pay
{
    public const PAY_TYPE_L = 'L';  // 支付宝扫码

    public const PAY_TYPE_V = 'V';  // 支付宝应用支付（生活号、小程序）

    public const PAY_TYPE_I = 'I';  // 微信扫码

    public const PAY_TYPE_W = 'W';  // 微信公众号支付

    public const PAY_TYPE_20 = '20';  // 微信小程序

    public const PAY_TYPE_23 = '23';  // 银联云闪付

    public const PAY_TYPE_U = 'U';  // 银联二维码

    public const PAY_TYPE_WECHAT_MICROPAY = 'WECHAT_MICROPAY';  // 微信付款码

    public const PAY_TYPE_ALIPAY_MICROPAY = 'ALIPAY_MICROPAY';  // 支付宝付款码

    public const PAY_TYPE_UNIONPAY_MICROPAY = 'UNIONPAY_MICROPAY';  // 银联付款码

    public const PAY_TYPE_DC_MICROPAY = 'DC_MICROPAY';  // 数字人民币付款码

    /**
     * 网关支付方式.
     */
    public const GATEWAY_PAY_TYPE = ['L', 'V', 'I', 'W', '20', '23', 'U'];

    /**
     * 聚合支付方式.
     */
    public const AGGREGATION_PAY_TYPE = ['WECHAT_MICROPAY', 'ALIPAY_MICROPAY', 'UNIONPAY_MICROPAY', 'DC_MICROPAY'];

    /**
     * 签名方式.
     */
    public const SIGN_TYPE = 'RSA';

    /**
     * 签名.
     *
     * @param string $data        排序后的字符串集
     * @param string $private_key 私钥路径
     *
     * @return string
     */
    public function sign($data, $private_key)
    {
        $private_key = openssl_get_privatekey(file_get_contents($private_key));

        openssl_sign($data, $sign, $private_key, OPENSSL_ALGO_MD5);

        openssl_free_key($private_key);

        return base64_encode($sign);
    }

    /**
     * RSA验签.
     *
     * @param string $data       待签名数据(需要先排序，然后拼接)
     * @param string $sign       签名
     * @param string $public_key 公钥路径
     *
     * @return bool
     */
    public function rsaVerify(string $data, string $sign, string $public_key)
    {
        $public_key = openssl_get_publickey(file_get_contents($public_key));

        $result = (bool) openssl_verify($data, base64_decode($sign), $public_key, OPENSSL_ALGO_MD5);

        openssl_free_key($public_key);

        return $result;
    }

    /**
     * 生成pay_load.
     *
     * @param string $json_string         请求体json串
     * @param string $lianlian_public_key 连连公钥路径
     *
     * @return string
     */
    public function payLoad($json_string, $lianlian_public_key)
    {
        $lianlian_public_key = openssl_pkey_get_public(file_get_contents($lianlian_public_key));
        $hash_hmac_key = $this->random(32);
        $version = 'lianpay1_0_1';
        $aes_key = $this->random(32);
        $nonce = $this->random(8);

        return $this->lianlianpayEncrypt($json_string, $lianlian_public_key, $hash_hmac_key, $version, $aes_key, $nonce);
    }

    /**
     * 连连支付加密.
     *
     * @param string $json_string         请求体json串
     * @param string $lianlian_public_key 连连公钥路径
     * @param string $hash_hmac_key       使用HMAC生成信息摘要时所使用的密钥
     * @param string $version             版本号
     * @param string $aes_key             AES加密口令
     * @param string $nonce               AES加密非NULL的初始化向量
     */
    public function lianlianpayEncrypt($json_string, $lianlian_public_key, $hash_hmac_key, $version, $aes_key, $nonce)
    {
        $base64_hash_hmac_key = $this->rsaEncrypt($hash_hmac_key, $lianlian_public_key);
        $base64_aes_key = $this->rsaEncrypt($aes_key, $lianlian_public_key);
        $base64_nonce = base64_encode($nonce);
        $encry = $this->aesEncrypt(iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $json_string), $aes_key, $nonce);
        $message = $base64_nonce.'$'.$encry;
        $sign = hex2bin(hash_hmac('sha256', $message, $hash_hmac_key));
        $base64_sign = base64_encode($sign);

        return $version.'$'.$base64_hash_hmac_key.'$'.$base64_aes_key.'$'.$base64_nonce.'$'.$encry.'$'.$base64_sign;
    }

    /**
     * 字母数字组合随机数.
     *
     * @param int $length 随机数长度
     *
     * @return string
     */
    public function random($length)
    {
        $data = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', 'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z'];
        $random = '';
        $count = count($data) - 1;
        for ($i = 0; $i < $length; ++$i) {
            $random .= $data[rand(0, $count)];
        }

        return $random;
    }

    /**
     * 参数字典排序.
     *
     * @param array $data 请求参数
     *
     * @return string
     */
    public function format($data)
    {
        ksort($data);
        $string = '';
        foreach ($data as $k => $v) {
            if ('' != $v && !is_array($v)) {
                $string .= $k.'='.$v.'&';
            }
        }

        return rtrim($string, '&');
    }

    /**
     * AES加密.
     */
    public function aesEncrypt($data, $key, $nonce)
    {
        return base64_encode(openssl_encrypt($data, 'AES-256-CTR', $key, true, $nonce."\0\0\0\0\0\0\0\1"));
    }

    /**
     * AES解密.
     */
    public function aesDecrypt($data, $key, $nonce)
    {
        return openssl_decrypt(base64_decode($data), 'AES-256-CTR', $key, true, $nonce."\0\0\0\0\0\0\0\1");
    }

    /**
     * RSA公钥加密.
     *
     * @param string $data       待加密数据
     * @param string $public_key 公钥
     *
     * @return string
     */
    public function rsaEncrypt($data, $public_key)
    {
        openssl_public_encrypt($data, $encrypted, $public_key, OPENSSL_PKCS1_OAEP_PADDING);

        return base64_encode($encrypted);
    }

    /**
     * RSA私钥解密.
     *
     * @param string $data        待解密数据
     * @param string $private_key 私钥
     *
     * @return string
     */
    public function rsaDecrypt($data, $private_key)
    {
        return openssl_private_decrypt(base64_decode($data), $decrypted, $private_key, OPENSSL_PKCS1_OAEP_PADDING);
    }
}
