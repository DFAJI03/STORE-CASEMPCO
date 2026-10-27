<?php
// includes/functions.php
// Small helpers used on every page.

// Safely print text inside HTML (stops <script> tricks, called XSS).
function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Format money, e.g. 1234.5 -> 1,234.50
function money($value): string
{
    return number_format((float)$value, 2);
}
