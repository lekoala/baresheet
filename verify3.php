<?php
// Test with valid multi-byte UTF-8 string, does it change?
$str = "こんにちは 😄 a string with accents éàç.";
$res = htmlspecialchars($str, ENT_XML1 | ENT_COMPAT, 'UTF-8');
echo ($str === $res) ? "Valid UTF-8 is unchanged\n" : "Valid UTF-8 changed!\n";
