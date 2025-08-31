<?php namespace LsPub;

class LsPubException extends \Exception {
}

class HttpException extends LsPubException {
    public function __construct($msg, $code=500) {
        $this->code = (int)$code;

        parent::__construct($msg);
    }
}

class RedirectException extends HttpException {
    public function __construct($msg, $code=null) {
        parent::__construct($msg, $code);
    }
}

?>
