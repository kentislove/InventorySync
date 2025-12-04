<?php

class HMacSha512 {
    private $_secretKey;

    public function __construct($secretKey) 
    {
        $this->_secretKey = $secretKey;
    }
    
    public function hash($data) 
    {
        $hashCode = hash_hmac("sha512", $data, $this->_secretKey);
        return strtoupper($hashCode);
    }
}