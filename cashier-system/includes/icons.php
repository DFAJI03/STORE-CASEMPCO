<?php
// includes/icons.php
// A small set of built-in icons. They are drawn in code (SVG), so no image files are needed,
// they stay sharp at any size, and they take the color of the text around them.
//
// How to use it inside a page (write it like any other PHP echo):
//     echo icon('cart');                      draws an 18 pixel icon in the color of the text
//     echo icon('alert', 22, 'text-danger');  draws a 22 pixel red icon
//
// Names: home, cart, clock, box, purchase, wallet, users, report, chart, trending,
//        banknote, alert, export, printer, search, plus, list, logout, pie, check, calendar

function icon(string $name, int $size = 18, string $class = ''): string
{
    static $shapes = [
        'home'     => '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>',
        'cart'     => '<circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.7 12.4a1 1 0 0 0 1 .8h8.9a1 1 0 0 0 1-.8L20 7H6"/>',
        'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'box'      => '<path d="M21 8l-9-5-9 5v8l9 5 9-5z"/><path d="M3 8l9 5 9-5"/><path d="M12 13v8"/>',
        'purchase' => '<path d="M12 3v12"/><path d="M7 11l5 5 5-5"/><path d="M4 21h16"/>',
        'wallet'   => '<rect x="3" y="6" width="18" height="14" rx="2"/><path d="M3 10h18"/><circle cx="16.5" cy="15" r="1"/>',
        'users'    => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.6 2.9-6 6.5-6s6.5 2.4 6.5 6"/><circle cx="17.5" cy="9" r="2.5"/><path d="M17 14c2.8 0 4.5 1.8 4.5 4.5"/>',
        'report'   => '<path d="M5 3h14v18H5z"/><path d="M9 17v-4"/><path d="M12 17V9"/><path d="M15 17v-6"/>',
        'chart'    => '<path d="M4 20V10"/><path d="M10 20V4"/><path d="M16 20v-8"/><path d="M22 20H2"/>',
        'trending' => '<path d="M3 17l6-6 4 4 8-8"/><path d="M15 7h6v6"/>',
        'banknote' => '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 10v4"/><path d="M18 10v4"/>',
        'alert'    => '<path d="M12 3l10 18H2z"/><path d="M12 10v5"/><path d="M12 18v.01"/>',
        'export'   => '<path d="M14 3H6a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8z"/><path d="M14 3v5h5"/><path d="M12 11v6"/><path d="M9.5 14.5L12 17l2.5-2.5"/>',
        'printer'  => '<path d="M7 9V3h10v6"/><rect x="3" y="9" width="18" height="8" rx="2"/><path d="M7 14h10v7H7z"/>',
        'search'   => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/>',
        'plus'     => '<path d="M12 5v14"/><path d="M5 12h14"/>',
        'list'     => '<path d="M8 4v16"/><path d="M4 8l4-4 4 4"/><path d="M16 20V4"/><path d="M12 16l4 4 4-4"/>',
        'logout'   => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
        'pie'      => '<path d="M12 3v9h9"/><path d="M21 12A9 9 0 1 1 12 3"/>',
        'check'    => '<path d="M5 12l5 5L20 7"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18"/><path d="M8 3v4"/><path d="M16 3v4"/>',
    ];

    if (!isset($shapes[$name])) {
        return '';   // an unknown name draws nothing, instead of breaking the page
    }

    return '<svg class="icon ' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '" width="' . $size . '" height="' . $size . '"'
         . ' viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
         . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $shapes[$name] . '</svg>';
}