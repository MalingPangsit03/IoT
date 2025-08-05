<?php
// test_otp.php

require_once __DIR__ . '/../config/config.php';


$to_email = 'radicaltoken1003@gmail.com';  // 🔁 Replace with your actual email
$otp_code = 'csbe qmur icvj oumo';

send_otp_email($to_email, $otp_code);

echo "OTP sent to $to_email: $otp_code";
