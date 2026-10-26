package com.simmanager.collector

import org.junit.Assert.*
import org.junit.Test

class Android6CompatibilityTest {
 @Test fun activeLegacySubscriptionCanRouteWithoutInventingPhysicalReadiness() {
  for(sdk in 23..25)assertTrue(subscriptionRoutingReady(sdk,null,true,"unsupported_android_version"))
  assertFalse(subscriptionRoutingReady(23,null,false,"unsupported_android_version"))
  assertFalse(subscriptionRoutingReady(23,null,true,null))
  assertFalse(subscriptionRoutingReady(23,2,true,"unsupported_android_version"))
  assertFalse(subscriptionRoutingReady(26,null,true,"unsupported_android_version"))
  assertFalse(subscriptionRoutingReady(35,0,true,"unsupported_android_version"))
  assertTrue(subscriptionRoutingReady(35,5,false,null))
 }

 @Test fun legacyNumberHintsRequireExplicitConsentAndPhonePermission() {
  assertFalse(canReadSimPhoneHint(23,true,false,false))
  assertFalse(canReadSimPhoneHint(23,false,false,true))
  assertTrue(canReadSimPhoneHint(23,true,false,true))
  assertTrue(canReadSimPhoneHint(25,true,false,true))
 }
 @Test fun modernNumberHintsKeepTheirOwnRuntimePermission() {
  assertFalse(canReadSimPhoneHint(26,true,false,true))
  assertTrue(canReadSimPhoneHint(26,false,true,false))
  assertFalse(canReadSimPhoneHint(35,true,false,true))
  assertTrue(canReadSimPhoneHint(35,true,true,false))
 }
 @Test fun legacyNumbersNeverReadTheDefaultSimAsAScopedFallback() {
  assertEquals("+8613800138000", resolveSimPhoneHint(23,{"13800138000"},{error("must not read default SIM")}))
  assertNull(resolveSimPhoneHint(23,{null},{error("must not read default SIM")}))
  assertEquals("+639171234567", resolveSimPhoneHint(24,{null},{"09171234567"}))
 }
 @Test fun subscriptionNumberRemainsAssociatedWithItsOwnCard() {
  assertEquals("+8613800138000", resolveSimPhoneHint(23,{"13800138000"},{"13900139000"}))
  assertEquals("+8613900139000", resolveSimPhoneHint(23,{"13900139000"},{"13800138000"}))
 }
}
