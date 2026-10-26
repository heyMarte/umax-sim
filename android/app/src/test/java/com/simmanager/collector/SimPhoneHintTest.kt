package com.simmanager.collector

import org.junit.Assert.*
import org.junit.Test

class SimPhoneHintTest {
 @Test fun chinaMobileFormats() {
  for(raw in listOf("13800138000", "+86 138-0013-8000", "008613800138000", "8613800138000"))
   assertEquals("+8613800138000", normalizeSimPhoneHint(raw))
 }
 @Test fun philippinesMobileFormats() {
  for(raw in listOf("09171234567", "9171234567", "+63 (917) 123-4567", "00639171234567", "639171234567"))
   assertEquals("+639171234567", normalizeSimPhoneHint(raw))
 }
 @Test fun unavailableOrUnsupportedIsNeverInvented() {
  for(raw in listOf(null, "", "unknown", "00000000000", "12345", "+12025550123", "13800138000 ext 2", "+631234567890", "138001380000", "++8613800138000", "+13800138000"))
   assertNull(raw, normalizeSimPhoneHint(raw))
 }
 @Test fun confirmedBindingAlwaysWinsOverHint() {
  assertEquals("+8613900139000", simPhoneHeading("+8613900139000", "+8613800138000", false))
  assertEquals("+8613800138000 · 待确认", simPhoneHeading(null,"+8613800138000",false))
  assertEquals("已绑定号码 · 待同步", simPhoneHeading(null,"+8613800138000",true))
  assertEquals("待识别号码", simPhoneHeading(null,null,false))
 }
}
