<?php
/** Signs that a new customer account was made by a bot or with a throwaway address (twin of web/lib/signup-check.ts). */

const SARALA_DISPOSABLE = [
    'mailinator.com', 'mailinator.net', 'mailinator.org', 'guerrillamail.com', 'guerrillamail.net',
    'guerrillamail.org', 'guerrillamail.biz', 'guerrillamail.de', 'guerrillamailblock.com', 'sharklasers.com',
    'grr.la', 'pokemail.net', 'spam4.me', '10minutemail.com', '10minutemail.net', '10minutemail.co.uk',
    '10minemail.com', '20minutemail.com', 'temp-mail.org', 'temp-mail.io', 'tempmail.com', 'tempmail.net',
    'tempmail.dev', 'tempmailo.com', 'tempmail.plus', 'tempail.com', 'tempinbox.com', 'tempr.email',
    'temporary-mail.net', 'throwawaymail.com', 'trashmail.com', 'trashmail.de', 'trashmail.net', 'trash-mail.com',
    'yopmail.com', 'yopmail.fr', 'yopmail.net', 'cool.fr.nf', 'jetable.fr.nf', 'nospam.ze.tc', 'nomail.xl.cx',
    'mega.zik.dj', 'speed.1s.fr', 'courriel.fr.nf', 'moncourrier.fr.nf', 'monemail.fr.nf', 'monmail.fr.nf',
    'getnada.com', 'nada.email', 'dispostable.com', 'maildrop.cc', 'mailnesia.com', 'mailcatch.com', 'mintemail.com',
    'mytemp.email', 'emailondeck.com', 'fakeinbox.com', 'fakemail.net', 'fakemailgenerator.com', 'emailfake.com',
    'email-fake.com', 'burnermail.io', '33mail.com', 'spamgourmet.com', 'spambox.us', 'spamex.com',
    'mailforspam.com', 'mohmal.com', 'discard.email', 'discardmail.com', 'dropmail.me', 'emltmp.com',
    'inboxkitten.com', 'harakirimail.com', 'mailpoof.com', 'moakt.com', 'tmail.ws', 'tmpmail.org', 'tmpmail.net',
    'tmpeml.com', '1secmail.com', '1secmail.net', '1secmail.org', 'esiix.com', 'wwjmp.com', 'xojxe.com', 'yoggm.com',
    'laafd.com', 'txcct.com', 'rteet.com', 'dpptd.com', 'kzccv.com', 'qiott.com', 'vjuum.com', 'linshiyouxiang.net',
    'bccto.me', 'chacuo.net', '027168.com', 'mailtemp.net', 'minuteinbox.com', 'spamdecoy.net', 'mail-temp.com',
    'luxusmail.org', 'byom.de', 'wegwerfmail.de', 'wegwerfmail.net', 'einrot.com', 'trbvm.com', 'armyspy.com',
    'cuvox.de', 'dayrep.com', 'fleckens.hu', 'gustr.com', 'jourrapide.com', 'rhyta.com', 'superrito.com',
    'teleworm.us', 'mvrht.com', 'mailbox.in.ua', 'anonbox.net', 'anonymbox.com', 'deadaddress.com', 'mailexpire.com',
    'incognitomail.com', 'spamthisplease.com', 'trashymail.com', 'getairmail.com', 'airmail.cc', 'zetmail.com',
    'vomoto.com', 'boximail.com', 'clrmail.com', 'emailtemporario.com.br', 'correotemporal.org', 'mailtothis.com',
    'gufum.com', 'lroid.com', 'mailsac.com', 'fexpost.com', 'fextemp.com', 'tempmailin.com', 'emailnax.com',
    'smartnator.com', 'eyepaste.com', 'pecinan.com', 'kuku.lu', 'haribu.net', 'hotprice.co', 'tafmail.com',
];

function sarala_vowel_share(string $s): float
{
    $letters = preg_replace('/[^a-z]/', '', $s);
    if ($letters === '') return 1.0;
    return preg_match_all('/[aeiouy]/', $letters) / strlen($letters);
}

/** @return array{suspicious: bool, reasons: string[]} */
function sarala_check_signup(?string $email, ?string $first, ?string $last): array
{
    static $set = null;
    $set ??= array_flip(SARALA_DISPOSABLE);
    $reasons = [];
    $email = strtolower(trim((string) $email));
    $at = strrpos($email, '@');
    if ($at !== false && $at > 0) {
        $local = substr($email, 0, $at);
        $domain = substr($email, $at + 1);
        $base = implode('.', array_slice(explode('.', $domain), -2));
        if (isset($set[$domain]) || isset($set[$base])) $reasons[] = 'Throwaway email service';
        $plain = preg_replace('/[._+-]/', '', $local);
        if (strlen($plain) >= 10 && preg_match('/\d{6,}/', $plain)) {
            $reasons[] = 'Email made of long random numbers';
        } elseif (strlen($plain) >= 12 && preg_match('/^[a-z0-9]+$/', $plain) && sarala_vowel_share($plain) < 0.15) {
            $reasons[] = 'Email looks randomly generated';
        }
    }
    $name = trim(($first ?? '') . ' ' . ($last ?? ''));
    if (preg_match('~https?://|www\.|\.(com|ru|cn|xyz|top)\b~i', $name)) {
        $reasons[] = 'A link in the name';
    } elseif (preg_match('/[\x{0400}-\x{04FF}].*[a-z]|[a-z].*[\x{0400}-\x{04FF}]/iu', $name) && mb_strlen($name) > 25) {
        $reasons[] = 'Name mixes scripts like spam bots do';
    }
    $f = mb_strtolower(trim((string) $first));
    $l = mb_strtolower(trim((string) $last));
    if (mb_strlen($f) >= 6 && $f === $l) $reasons[] = 'First and last name are the same';
    return ['suspicious' => (bool) $reasons, 'reasons' => $reasons];
}
