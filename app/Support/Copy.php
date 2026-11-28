<?php

namespace App\Support;

use Illuminate\Support\Js;

/**
 * An Alpine click handler that copies text. Browsers only allow the
 * clipboard API on https (and localhost); on a plain http address, like a
 * LAN IP, the old way (a hidden text field and "copy") is used instead.
 */
final class Copy
{
    public static function handler(string $text): string
    {
        return '((t) => { if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(t) } else { '
            .'const a = document.createElement(\'textarea\'); a.value = t; a.setAttribute(\'readonly\', \'\'); a.style.position = \'fixed\'; a.style.opacity = \'0\'; '
            .'document.body.appendChild(a); a.select(); document.execCommand(\'copy\'); a.remove() } })('.Js::from($text).'); $tooltip('.Js::from(__('Copied')).')';
    }
}
