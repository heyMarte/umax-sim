package com.simmanager.collector

/** SIM-provided numbers are suggestions, never proof of number ownership. */
internal fun normalizeSimPhoneHint(raw:String?):String? {
 val text=raw?.trim()?.takeIf{it.isNotEmpty()}?:return null
 if(!text.matches(Regex("[+0-9 ()\\-]+")))return null
 val compact=text.replace(Regex("[ ()\\-]"),"")
 val international=compact.startsWith("+")||compact.startsWith("00")
 val digits=when {compact.startsWith("+")->compact.drop(1);compact.startsWith("00")->compact.drop(2);else->compact}
 if(!digits.matches(Regex("[0-9]+")))return null
 return when {
  digits.matches(Regex("861[3-9][0-9]{9}"))->"+$digits"
  digits.matches(Regex("639[0-9]{9}"))->"+$digits"
  international->null
  digits.matches(Regex("1[3-9][0-9]{9}"))->"+86$digits"
  digits.matches(Regex("09[0-9]{9}"))->"+63${digits.drop(1)}"
  digits.matches(Regex("9[0-9]{9}"))->"+63$digits"
  else->null
 }
}

internal fun simPhoneHeading(bound:String?,hint:String?,hasBinding:Boolean):String =
 bound?.takeIf{it.isNotBlank()} ?: if(hasBinding)"已绑定号码 · 待同步" else
 normalizeSimPhoneHint(hint)?.let{"$it · 待确认"} ?: "待识别号码"

/** READ_PHONE_NUMBERS does not exist before API26; READ_PHONE_STATE alone is not consent. */
internal fun canReadSimPhoneHint(sdk:Int,phoneState:Boolean,phoneNumbers:Boolean,legacyConsent:Boolean):Boolean =
 if(sdk>=26)phoneNumbers else phoneState&&legacyConsent

/** A default TelephonyManager on Android6 is not a per-card source. Never consult it. */
internal fun resolveSimPhoneHint(sdk:Int,subscriptionNumber:()->String?,scopedNumber:()->String?):String? {
 if(sdk<24)return normalizeSimPhoneHint(subscriptionNumber())
 return normalizeSimPhoneHint(scopedNumber())?:normalizeSimPhoneHint(subscriptionNumber())
}
