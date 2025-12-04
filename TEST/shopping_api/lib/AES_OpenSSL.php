<?php

/**
 * Yahoo 的範例程式，加解密用
 */
class AES_OpenSSL 
{
    private $_secretKey;
    private $_iv;
	private $_method = "AES-256-CBC";

    public function __construct($secretKey, $iv) 
    {
        $this->_secretKey = base64_decode($secretKey);
        $this->_iv = base64_decode($iv);	
    }
    
    function encryptString($plainText) 
    { 
		$enc = openssl_encrypt($plainText, $this->_method, $this->_secretKey, OPENSSL_RAW_DATA, $this->_iv);
        return base64_encode($enc); 
    } 
    
    function decryptString($encryptedText) 
    { 
		$base64DecodedText = base64_decode($encryptedText);
		return openssl_decrypt($base64DecodedText, $this->_method, $this->_secretKey, OPENSSL_RAW_DATA, $this->_iv);
    } 
}



class AES {
    const OPENSSL_CIPHER_NAME = "aes-256-cbc";
    const CIPHER_KEY_LEN = 32;

    private $_key;
    private $_iv;

    public function __construct($key, $iv)
    {
        $base64decodedKey = base64_decode($key);
        if (strlen($base64decodedKey) < AES::CIPHER_KEY_LEN) {
            $this->_key = str_pad($base64decodedKey, AES::CIPHER_KEY_LEN, "0");
        } else if (strlen($base64decodedKey) > AES::CIPHER_KEY_LEN) {
            $this->_key = substr($base64decodedKey, 0, AES::CIPHER_KEY_LEN);
        } else {
            $this->_key = $base64decodedKey;
        }
        $this->_iv = base64_decode($iv);
    }

    public function encrypt($data)
    {
        return base64_encode(openssl_encrypt($data, AES::OPENSSL_CIPHER_NAME, $this->_key, OPENSSL_RAW_DATA, $this->_iv));
    }
}

class HMac {
    private $_secretKey;

    public function __construct($secretKey)
    {
        $this->_secretKey = $secretKey;
    }

    public function sha512($data)
    {
        return hash_hmac("sha512", $data, $this->_secretKey);
    }
}