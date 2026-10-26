package com.simmanager.collector

internal enum class StartPermissionOutcome { START, REQUIRED_MISSING, CANCELED }

/** Request all supported runtime capabilities once; later starts retry only essentials. */
internal fun startPermissionBatch(sdk:Int,cameraAvailable:Boolean,granted:Set<String>,optionalAlreadyAsked:Boolean):List<String> {
 val permissions=mutableListOf("android.permission.RECEIVE_SMS","android.permission.READ_PHONE_STATE")
 if(!optionalAlreadyAsked){
  if(sdk>=26)permissions.add("android.permission.READ_PHONE_NUMBERS")
  if(sdk>=33)permissions.add("android.permission.POST_NOTIFICATIONS")
  if(cameraAvailable)permissions.add("android.permission.CAMERA")
  permissions.add("android.permission.ACCESS_COARSE_LOCATION")
 }
 return permissions.filter{it !in granted}
}

internal fun startPermissionOutcome(requiredGranted:Boolean,callbackHasResults:Boolean):StartPermissionOutcome =
 if(!callbackHasResults)StartPermissionOutcome.CANCELED else if(requiredGranted)StartPermissionOutcome.START else StartPermissionOutcome.REQUIRED_MISSING

internal fun phoneHintSampleCurrent(sampledAllowed:Boolean,currentAllowed:Boolean,sampledConsentRevision:Long,currentConsentRevision:Long):Boolean =
 sampledAllowed==currentAllowed&&sampledConsentRevision==currentConsentRevision
