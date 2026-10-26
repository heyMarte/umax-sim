package com.simmanager.collector
import org.junit.Assert.*
import org.junit.Test
class SetupStateTest {
 @Test fun requiredAndOptionalMissingHaveDifferentStatuses(){assertEquals("需要开启",setupPermission(false,true).status);assertEquals("可选 · 未开启",setupPermission(false,false).status);assertEquals("已开启",setupPermission(true,true).status)}
 @Test fun manualConfirmationNeverPretendsToBeDetection(){assertEquals("需手动确认",setupManual(false).status);assertEquals("已手动确认",setupManual(true).status);assertFalse(setupManual(true).detected)}
 @Test fun connectionNeverClaimsSuccessForServiceOnly(){assertEquals("启动中",connectionState(true,0,0,true,1000));assertEquals("连接失败",connectionState(true,0,100,false,1000));assertEquals("已连接后台",connectionState(true,900,800,false,1000));assertEquals("已暂停",connectionState(false,900,800,false,1000))}
}
