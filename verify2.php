<?php
// Let's test htmlspecialchars replacement behavior with invalid UTF-8 and other chars.
// The list above showed many high-bit characters were "changed" because they are invalid UTF-8 bytes
// and get stripped/replaced.

$str = "";
for ($i = 0; $i < 256; $i++) {
    $c = chr($i);
    $res = htmlspecialchars($c, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    if ($c !== $res && $res !== '') { // ignore empty string, which means invalid utf-8 stripped
        echo "char " . bin2hex($c) . " ($c) -> " . $res . "\n";
    }
}
