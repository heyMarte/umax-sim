package com.simmanager.collector
import org.junit.Assert.*
import org.junit.Test
class AutoUpdatePolicyTest {
 @Test fun throttlesByConfigurationAndToleratesClockRollback(){assertTrue(AutoUpdatePolicy.due(true,true,0,100));assertFalse(AutoUpdatePolicy.due(true,true,100,200));assertTrue(AutoUpdatePolicy.due(true,true,100,3600100));assertTrue(AutoUpdatePolicy.due(true,true,200,100));assertFalse(AutoUpdatePolicy.due(false,true,0,100));assertFalse(AutoUpdatePolicy.due(true,false,0,100))}
 @Test fun consentNeedsForegroundCurrentSnapshotAndVerifiedApk(){assertTrue(AutoUpdatePolicy.install(true,true,true,true,true));for(i in 0..4){val flags=MutableList(5){true};flags[i]=false;assertFalse(AutoUpdatePolicy.install(flags[0],flags[1],flags[2],flags[3],flags[4]))}}
 @Test fun postponeExpiresAndManualCheckCanBypassIt(){assertFalse(AutoUpdatePolicy.prompt(false,1000,999));assertTrue(AutoUpdatePolicy.prompt(false,1000,1000));assertTrue(AutoUpdatePolicy.prompt(true,1000,999))}
}
