package com.simmanager.collector
object AutoUpdatePolicy {
 fun due(configured:Boolean,foreground:Boolean,last:Long,now:Long)=configured&&foreground&&(last<=0||now<last||now-last>=3600000)
 fun install(consent:Boolean,foreground:Boolean,current:Boolean,verified:Boolean,permission:Boolean)=consent&&foreground&&current&&verified&&permission
 fun prompt(manual:Boolean,postponedUntil:Long,now:Long)=manual||now>=postponedUntil
}
