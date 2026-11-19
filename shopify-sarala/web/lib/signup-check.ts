/**
 * Signs that a new customer account was made by a bot or with a throwaway address. The app never
 * deletes on its own: it tags the customer so the merchant can review the list.
 */

/** Common disposable (throwaway) email services. */
export const DISPOSABLE_DOMAINS = new Set([
  "mailinator.com", "mailinator.net", "mailinator.org", "guerrillamail.com", "guerrillamail.net", "guerrillamail.org",
  "guerrillamail.biz", "guerrillamail.de", "guerrillamailblock.com", "sharklasers.com", "grr.la", "pokemail.net", "spam4.me",
  "10minutemail.com", "10minutemail.net", "10minutemail.co.uk", "10minemail.com", "20minutemail.com", "temp-mail.org",
  "temp-mail.io", "tempmail.com", "tempmail.net", "tempmail.dev", "tempmailo.com", "tempmail.plus", "tempail.com",
  "tempinbox.com", "tempr.email", "temporary-mail.net", "throwawaymail.com", "trashmail.com", "trashmail.de", "trashmail.net",
  "trash-mail.com", "yopmail.com", "yopmail.fr", "yopmail.net", "cool.fr.nf", "jetable.fr.nf", "nospam.ze.tc", "nomail.xl.cx",
  "mega.zik.dj", "speed.1s.fr", "courriel.fr.nf", "moncourrier.fr.nf", "monemail.fr.nf", "monmail.fr.nf", "getnada.com",
  "nada.email", "dispostable.com", "maildrop.cc", "mailnesia.com", "mailcatch.com", "mintemail.com", "mytemp.email",
  "emailondeck.com", "fakeinbox.com", "fakemail.net", "fakemailgenerator.com", "emailfake.com", "email-fake.com",
  "burnermail.io", "33mail.com", "spamgourmet.com", "spambox.us", "spamex.com", "mailforspam.com", "mohmal.com",
  "discard.email", "discardmail.com", "dropmail.me", "emltmp.com", "inboxkitten.com", "harakirimail.com", "mailpoof.com",
  "moakt.com", "tmail.ws", "tmpmail.org", "tmpmail.net", "tmpeml.com", "1secmail.com", "1secmail.net", "1secmail.org",
  "esiix.com", "wwjmp.com", "xojxe.com", "yoggm.com", "laafd.com", "txcct.com", "rteet.com", "dpptd.com", "kzccv.com",
  "qiott.com", "vjuum.com", "linshiyouxiang.net", "bccto.me", "chacuo.net", "027168.com", "mailtemp.net", "minuteinbox.com",
  "spamdecoy.net", "mail-temp.com", "luxusmail.org", "byom.de", "wegwerfmail.de", "wegwerfmail.net", "einrot.com",
  "trbvm.com", "armyspy.com", "cuvox.de", "dayrep.com", "fleckens.hu", "gustr.com", "jourrapide.com", "rhyta.com",
  "superrito.com", "teleworm.us", "mvrht.com", "mailbox.in.ua", "anonbox.net", "anonymbox.com", "deadaddress.com",
  "mailexpire.com", "incognitomail.com", "spamthisplease.com", "trashymail.com", "getairmail.com", "airmail.cc",
  "zetmail.com", "vomoto.com", "boximail.com", "clrmail.com", "emailtemporario.com.br", "correotemporal.org",
  "mailtothis.com", "gufum.com", "lroid.com", "mailsac.com", "fexpost.com", "fextemp.com", "tempmailin.com",
  "emailnax.com", "smartnator.com", "eyepaste.com", "pecinan.com", "kuku.lu", "haribu.net", "hotprice.co", "tafmail.com",
]);

export interface SignupCheck {
  suspicious: boolean;
  reasons: string[];
}

/** Share of letters that are vowels: random strings ("xkqzvtrw") have very few. */
function vowelShare(s: string): number {
  const letters = s.replace(/[^a-z]/g, "");
  if (letters.length === 0) return 1;
  return (letters.match(/[aeiouy]/g)?.length ?? 0) / letters.length;
}

export function checkSignup(input: { email?: string | null; firstName?: string | null; lastName?: string | null }): SignupCheck {
  const reasons: string[] = [];
  const email = (input.email ?? "").trim().toLowerCase();
  const at = email.lastIndexOf("@");
  if (at > 0) {
    const local = email.slice(0, at);
    const domain = email.slice(at + 1);
    const base = domain.split(".").slice(-2).join(".");
    if (DISPOSABLE_DOMAINS.has(domain) || DISPOSABLE_DOMAINS.has(base)) {
      reasons.push("Throwaway email service");
    }
    const plain = local.replace(/[._+-]/g, "");
    if (plain.length >= 10 && /\d{6,}/.test(plain)) {
      reasons.push("Email made of long random numbers");
    } else if (plain.length >= 12 && /^[a-z0-9]+$/.test(plain) && vowelShare(plain) < 0.15) {
      reasons.push("Email looks randomly generated");
    }
  }
  const name = `${input.firstName ?? ""} ${input.lastName ?? ""}`.trim();
  if (/https?:\/\/|www\.|\.(com|ru|cn|xyz|top)\b/i.test(name)) {
    reasons.push("A link in the name");
  } else if (/[Ѐ-ӿ].*[a-z]|[a-z].*[Ѐ-ӿ]/i.test(name) && name.length > 25) {
    reasons.push("Name mixes scripts like spam bots do");
  }
  const first = (input.firstName ?? "").trim().toLowerCase();
  const last = (input.lastName ?? "").trim().toLowerCase();
  if (first.length >= 6 && first === last) {
    reasons.push("First and last name are the same");
  }
  return { suspicious: reasons.length > 0, reasons };
}
