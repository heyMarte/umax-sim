package com.simmanager.collector

import org.junit.Assert.*
import org.junit.Test

class StartPermissionsTest {
 private val sms="android.permission.RECEIVE_SMS"
 private val phone="android.permission.READ_PHONE_STATE"
 private val numbers="android.permission.READ_PHONE_NUMBERS"
 private val notification="android.permission.POST_NOTIFICATIONS"
 private val camera="android.permission.CAMERA"
 private val coarse="android.permission.ACCESS_COARSE_LOCATION"

 @Test fun modernStartBatchesEveryApplicableMissingRuntimePermission(){
  assertEquals(listOf(sms,phone,numbers,notification,camera,coarse),startPermissionBatch(35,true,emptySet(),false))
 }
 @Test fun legacyStartNeverRequestsUnavailablePermissionsOrCameraWithoutHardware(){
  assertEquals(listOf(sms,phone,coarse),startPermissionBatch(23,false,emptySet(),false))
  assertEquals(listOf(sms,phone,numbers,camera,coarse),startPermissionBatch(26,true,emptySet(),false))
 }
 @Test fun existingGrantsAreSkippedAndOptionalDenialsAreNotNaggedOnRepeatStart(){
  assertEquals(listOf(sms),startPermissionBatch(35,true,setOf(phone,numbers,notification,camera,coarse),false))
  assertEquals(listOf(phone),startPermissionBatch(35,true,setOf(sms),true))
  assertEquals(emptyList<String>(),startPermissionBatch(35,true,setOf(sms,phone),true))
 }
 @Test fun optionalDenialsNeverBlockButRequiredDenialsAndCanceledCallbacksDo(){
  assertEquals(StartPermissionOutcome.START,startPermissionOutcome(true,true))
  assertEquals(StartPermissionOutcome.REQUIRED_MISSING,startPermissionOutcome(false,true))
  assertEquals(StartPermissionOutcome.CANCELED,startPermissionOutcome(false,false))
  assertEquals(StartPermissionOutcome.CANCELED,startPermissionOutcome(true,false))
 }
 @Test fun consentOffOnOffDiscardsQueuedSampleEvenWhenFinalAccessMatches(){
  val queuedRevision=0L
  var consentRevision=queuedRevision
  consentRevision++ // Explicit opt-in while the queued worker can read a number.
  consentRevision++ // Withdrawal before its result reaches the UI.
  assertFalse(phoneHintSampleCurrent(false,false,queuedRevision,consentRevision))
  assertTrue(phoneHintSampleCurrent(false,false,consentRevision,consentRevision))
  assertFalse(phoneHintSampleCurrent(true,false,consentRevision,consentRevision))
 }
}
