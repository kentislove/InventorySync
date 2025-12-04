<?php
class AES {
    private $_secretKey;
    private $_iv;
    public function __construct($secretKey, $iv) {
        $this->_secretKey = base64_decode($secretKey);
        $this->_iv = base64_decode($iv);
    }
    
    function pkcs5_pad ($text, $blocksize) { 
        $pad = $blocksize - (strlen($text) % $blocksize); 
        return $text . str_repeat(chr($pad), $pad); 
    } 
    
    function pkcs5_unpad($text) { 
        $pad = ord($text{strlen($text)-1}); 
        if ($pad > strlen($text)) return false; 
        if (strspn($text, chr($pad), strlen($text) - $pad) != $pad) return false; 
        return substr($text, 0, -1 * $pad); 
    } 
    
    function encryptString($plainText) 
    { 
        $blockSize = mcrypt_get_block_size(MCRYPT_RIJNDAEL_128, MCRYPT_MODE_CBC); 
        $paddingPlainText = $this->pkcs5_pad($plainText, $blockSize); 
        $enc = mcrypt_encrypt(MCRYPT_RIJNDAEL_128, $this->_secretKey, $paddingPlainText, MCRYPT_MODE_CBC, $this->_iv);
        return base64_encode($enc); 
    } 
    
    function decryptString($encryptedText) 
    { 
        $base64DecodedText = base64_decode($encryptedText);
        $paddingPlainText = mcrypt_decrypt(MCRYPT_RIJNDAEL_128, $this->_secretKey, $base64DecodedText, MCRYPT_MODE_CBC, $this->_iv);     
        return $this->pkcs5_unpad($paddingPlainText);
    } 
}