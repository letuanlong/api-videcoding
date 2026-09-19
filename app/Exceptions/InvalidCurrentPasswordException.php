<?php

namespace App\Exceptions;

use Exception;

class InvalidCurrentPasswordException extends Exception
{
    // Custom Exception để bắn ra lỗi nghiệp vụ khi mật khẩu hiện tại không đúng
}
