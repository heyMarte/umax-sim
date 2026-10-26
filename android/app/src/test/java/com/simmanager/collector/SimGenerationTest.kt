package com.simmanager.collector

import org.junit.Assert.*
import org.junit.Test

class SimGenerationTest {
 private var next=0
 private fun policy()=SimGenerationPolicy { "token-${++next}".padEnd(36,'x') }
 private fun sim(id:Int=7,slot:Int=1,ready:Boolean=true,phone:String?="+8613800138000")=SimObservation(id,slot,ready,phone)
 @Test fun insertRemoveAndReinsertCannotReuseOldGeneration(){
  val p=policy();val a=p.observe(null,"a","ok",listOf(sim()))
  assertNotNull(a.readyToken(7));val removed=p.observe(a,"a","ok",emptyList());assertNull(removed.readyToken(7))
  val again=p.observe(removed,"a","ok",listOf(sim()));assertNotEquals(a.readyToken(7),again.readyToken(7))
 }
 @Test fun slotSwapFollowsSubscriptionsAndChangedPhoneRotatesOnlyThatSim(){
  val p=policy();val first=p.observe(null,"a","ok",listOf(sim(),sim(8,2,phone="+8613900139000")))
  val moved=p.observe(first,"a","ok",listOf(sim(7,2),sim(8,1,phone="+8613900139000")))
  assertEquals(first.readyToken(7),moved.readyToken(7));assertEquals(first.readyToken(8),moved.readyToken(8))
  val replaced=p.observe(moved,"a","ok",listOf(sim(7,2,phone="+8613600136000"),sim(8,1,phone="+8613900139000")))
  assertNotEquals(moved.readyToken(7),replaced.readyToken(7));assertEquals(moved.readyToken(8),replaced.readyToken(8))
 }
 @Test fun firstReadableHintRotatesUnknownManualGenerationBeforeReceipt(){
  val p=policy();val unknown=p.observe(null,"a","ok",listOf(sim(phone=null)))
  val readable=p.observe(unknown,"a","ok",listOf(sim(phone="+8613900139000")))
  assertNotEquals(unknown.readyToken(7),readable.readyToken(7))
 }
 @Test fun disappearingPhoneRetainsKnownHintForLaterReplacementDetection(){
  val p=policy();val first=p.observe(null,"a","ok",listOf(sim()))
  val missing=p.observe(first,"a","ok",listOf(sim(phone=null)));assertEquals(first.readyToken(7),missing.readyToken(7))
  val changed=p.observe(missing,"a","ok",listOf(sim(phone="+8613900139000")));assertNotEquals(first.readyToken(7),changed.readyToken(7))
 }
 @Test fun unavailableAndPermissionLossSuspendWithoutPretendingRemoval(){
  for(status in listOf("unavailable","permission_denied")){
   val p=policy();val first=p.observe(null,"a","ok",listOf(sim()))
   val hidden=p.observe(first,"a",status,emptyList());assertNull(hidden.readyToken(7));assertEquals(first.records,hidden.records)
   val recovered=p.observe(hidden,"a","ok",listOf(sim()));assertEquals(first.readyToken(7),recovered.readyToken(7))
   assertFalse(bindingTokenMatches(first,recovered,7,first.readyToken(7)))
  }
 }
 @Test fun readyTransitionsAndConfigurationChangesRotateTokens(){
  val p=policy();val first=p.observe(null,"a","ok",listOf(sim()))
  val locked=p.observe(first,"a","ok",listOf(sim(ready=false)));assertNull(locked.readyToken(7))
  val unlocked=p.observe(locked,"a","ok",listOf(sim()));assertNotEquals(first.readyToken(7),unlocked.readyToken(7))
  val changed=p.observe(unlocked,"b","ok",listOf(sim()));assertNotEquals(unlocked.readyToken(7),changed.readyToken(7))
 }
 @Test fun staleHeartbeatOrMissingTokenCannotProduceReceiptVersion(){
  val p=policy();val first=p.observe(null,"a","ok",listOf(sim()))
  val stable=p.observe(first,"a","ok",listOf(sim()));assertTrue(bindingTokenMatches(first,stable,7,first.readyToken(7)))
  assertFalse(bindingTokenMatches(first,stable,7,null));assertFalse(bindingTokenMatches(first,stable,8,first.readyToken(7)))
  val replacement=p.observe(stable,"a","ok",listOf(sim(phone="+8613900139000")))
  assertFalse(bindingTokenMatches(first,replacement,7,first.readyToken(7)))
  val newConfig=p.observe(first,"b","ok",listOf(sim()));assertFalse(bindingTokenMatches(first,newConfig,7,first.readyToken(7)))
 }
 @Test fun malformedDuplicateOrAmbiguousSlotNeverStamps(){
  val p=policy();val duplicate=p.observe(null,"a","ok",listOf(sim(),sim()))
  assertNull(duplicate.readyToken(7))
  val ambiguous=p.observe(null,"a","ok",listOf(sim(),sim(8,1)));assertNull(ambiguous.readyToken(7));assertNull(ambiguous.readyToken(8))
  assertNull(p.observe(null,"a","ok",listOf(sim(slot=0))).readyToken(7))
 }
 @Test fun nullSubscriptionListIsAuthoritativeOnlyWithExplicitAbsenceAndPermission(){
  assertEquals("ok",subscriptionSnapshotStatus(false,true,listOf(1,1)))
  assertEquals("ok",subscriptionSnapshotStatus(true,true,null))
  assertEquals("permission_denied",subscriptionSnapshotStatus(false,false,listOf(1,1)))
  for(states in listOf(null,emptyList(),listOf(0),listOf(1,0),listOf(1,5),listOf(2)))assertEquals("unavailable",subscriptionSnapshotStatus(false,true,states))
 }
 @Test fun stableUnknownIdentityCannotDistinguishSilentPhysicalReplacement(){
  val p=policy();val first=p.observe(null,"a","ok",listOf(sim(phone=null)))
  val same=p.observe(first,"a","ok",listOf(sim(phone=null)));assertEquals(first.readyToken(7),same.readyToken(7))
 }
}
