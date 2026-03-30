<?php
// Verify the control characters string matches the regex exactly.

$str = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x0B\x0C\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F";
$regexStr = "";

// Build the equivalent of the regex character class
for ($i = 0; $i <= 0x08; $i++) {
    $regexStr .= chr($i);
}
$regexStr .= chr(0x0B);
$regexStr .= chr(0x0C);
for ($i = 0x0E; $i <= 0x1F; $i++) {
    $regexStr .= chr($i);
}

if ($str === $regexStr) {
    echo "Control chars match perfectly.\n";
} else {
    echo "Control chars DO NOT match!\n";
    echo "str: " . bin2hex($str) . "\n";
    echo "reg: " . bin2hex($regexStr) . "\n";
}


// Verify htmlspecialchars behavior with ENT_XML1 | ENT_COMPAT
// According to docs, ENT_XML1:
// & -> &amp;
// < -> &lt;
// > -> &gt;
// " -> &quot;
// ' -> &apos; (only if ENT_QUOTES is used. ENT_COMPAT leaves ' alone)

$allChars = "";
for ($i = 0; $i < 256; $i++) {
    $allChars .= chr($i);
}

$escaped = htmlspecialchars($allChars, ENT_XML1 | ENT_COMPAT, 'UTF-8');

$changedChars = [];
for ($i = 0; $i < 256; $i++) {
    $c = chr($i);
    if ($c !== htmlspecialchars($c, ENT_XML1 | ENT_COMPAT, 'UTF-8')) {
        $changedChars[] = $c;
    }
}

echo "Chars changed by htmlspecialchars (ENT_XML1 | ENT_COMPAT):\n";
var_dump($changedChars);
