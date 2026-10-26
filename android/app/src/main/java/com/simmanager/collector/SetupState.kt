package com.simmanager.collector

data class SetupState(val status:String,val ready:Boolean,val detected:Boolean=true)
fun setupPermission(granted:Boolean,required:Boolean)=SetupState(if(granted)"已开启" else if(required)"需要开启" else "可选 · 未开启",granted)
fun setupManual(confirmed:Boolean)=SetupState(if(confirmed)"已手动确认" else "需手动确认",confirmed,false)
fun connectionState(running:Boolean,last:Long,attempt:Long,busy:Boolean,now:Long):String {
 if(!running)return "已暂停"
 if(busy)return if(last==0L)"启动中" else "正在同步"
 if(attempt>last)return "连接失败"
 if(last==0L)return "等待连接"
 if(now-last>180000)return "连接已中断"
 return "已连接后台"
}
