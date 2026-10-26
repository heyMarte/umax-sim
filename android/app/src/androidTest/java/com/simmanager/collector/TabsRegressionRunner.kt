package com.simmanager.collector

import android.app.Activity
import android.app.Instrumentation
import android.content.Intent
import android.os.Bundle
import android.view.View
import android.view.ViewGroup
import android.widget.TextView
import android.widget.ScrollView

/** Runs only on a disposable emulator: exercises actual visibility, navigation and recreation. */
class TabsRegressionRunner:Instrumentation(){
 override fun onCreate(args:Bundle?){super.onCreate(args);start()}
 override fun onStart(){val out=Bundle();try{
  val c=targetContext;val p=c.getSharedPreferences("config",0)
  Recovery.cancel(c);c.stopService(Intent(c,CollectorService::class.java));p.edit().clear().commit()
  fun launch()=startActivitySync(Intent(c,MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) as MainActivity
  fun all(v:View):List<View> = listOf(v)+(if(v is ViewGroup)(0 until v.childCount).flatMap{all(v.getChildAt(it))}else emptyList())
  fun visible(a:Activity,s:String)=all(a.window.decorView).filterIsInstance<TextView>().firstOrNull{it.text.toString().removePrefix("! ").removePrefix("✓ ")==s&&it.isShown}
  fun recreateAndResume(previous:MainActivity):MainActivity {
   val application=previous.application
   val created=java.util.concurrent.atomic.AtomicReference<MainActivity>()
   val resumed=java.util.concurrent.CountDownLatch(1)
   val destroyed=java.util.concurrent.CountDownLatch(1)
   val trace=java.util.Collections.synchronizedList(mutableListOf<String>())
   val callbacks=object:android.app.Application.ActivityLifecycleCallbacks {
    override fun onActivityCreated(activity:Activity,state:Bundle?){
     if(activity is MainActivity&&activity!==previous){created.set(activity);trace.add("created tab=${state?.getInt("selectedTab")}")}
    }
    override fun onActivityResumed(activity:Activity){if(activity===created.get()){trace.add("resumed");resumed.countDown()}}
    override fun onActivityDestroyed(activity:Activity){if(activity===previous){trace.add("old destroyed");destroyed.countDown()}}
    override fun onActivitySaveInstanceState(activity:Activity,state:Bundle){if(activity===previous)trace.add("saved tab=${state.getInt("selectedTab")}")}
    override fun onActivityStarted(activity:Activity){}
    override fun onActivityPaused(activity:Activity){}
    override fun onActivityStopped(activity:Activity){}
   }
   application.registerActivityLifecycleCallbacks(callbacks)
   try{
    runOnMainSync{previous.recreate()}
    check(resumed.await(10,java.util.concurrent.TimeUnit.SECONDS)){"Recreated Activity did not resume; trace=$trace oldDestroyed=${previous.isDestroyed}"}
    val recreated=created.get()?:error("Recreation did not create a new Activity; trace=$trace")
    check(recreated!==previous&&destroyed.count==0L){"Recreation did not replace the old Activity; trace=$trace"}
    val deadline=android.os.SystemClock.elapsedRealtime()+5000
    var visible=false
    // The lifecycle callback precedes WindowManager attaching/focusing the recreated window.
    while(!visible&&android.os.SystemClock.elapsedRealtime()<deadline){
     runOnMainSync{visible=recreated.hasWindowFocus()&&recreated.window.decorView.isShown&&!recreated.isDestroyed}
     if(!visible)Thread.sleep(25)
    }
    check(visible){"Recreated Activity did not become visible and focused; trace=$trace"}
    waitForIdleSync();return recreated
   }finally{application.unregisterActivityLifecycleCallbacks(callbacks)}
  }
  var a=launch()
  runOnMainSync{
   check(visible(a,"运行")!=null&&visible(a,"检查")!=null&&visible(a,"设置")!=null){"Three bottom navigation destinations must be visible"}
   check(visible(a,"扫码接入")!=null){"Fresh install opens setup"}
   check(visible(a,"服务器地址")==null){"Manual fields start collapsed"}
   visible(a,"检查")!!.performClick()
   check(visible(a,"短信权限 · 必需")!=null){"Required permission remains visible"}
   check(visible(a,"自启动 · 推荐")==null){"Recommended guidance starts collapsed"}
   check(visible(a,"收码检查（需另一部手机）")!=null)
   visible(a,"设置")!!.performClick()
   visible(a,"手动填写连接配置")!!.performClick()
   check(visible(a,"服务器地址")!=null)
   visible(a,"运行")!!.performClick()
   check(visible(a,"连接配置")==null){"Run must not contain config forms"}
   visible(a,"检查")!!.performClick()
  }
  waitForIdleSync()
  fun scroll()=MainActivity::class.java.getDeclaredField("screenScroll").apply{isAccessible=true}.get(a) as ScrollView
  var savedScroll=0
  runOnMainSync{scroll().scrollTo(0,180);savedScroll=scroll().scrollY;check(savedScroll>0){"Check page scrolls on small display"}}
  runOnMainSync{visible(a,"设置")!!.performClick()};waitForIdleSync()
  runOnMainSync{visible(a,"检查")!!.performClick()};waitForIdleSync()
  runOnMainSync{check(scroll().scrollY==savedScroll){"Each tab retains its scroll position"}}
  a=recreateAndResume(a)
  runOnMainSync{
   check(visible(a,"短信权限 · 必需")!=null){"Selected check page survives recreation"}
   check(scroll().scrollY==savedScroll){"Scroll position survives recreation"}
   MainActivity::class.java.getDeclaredMethod("show",String::class.java,String::class.java,Boolean::class.javaPrimitiveType).apply{isAccessible=true}.invoke(a,"远程反馈","info",true)
   check(visible(a,"短信权限 · 必需")!=null){"Feedback must not force Run tab"}
   val nav=visible(a,"运行")!!;var parent=nav.parent
   while(parent is View){check(parent !is ScrollView){"Navigation must stay outside scrolling content"};parent=parent.parent}
   a.finish()
  }
  p.edit().putString("url","https://old.invalid").putString("token",Vault.seal("saved")).commit()
  a=launch();runOnMainSync{check(visible(a,"采集已暂停")!=null){"Configured install opens Run"};visible(a,"设置")!!.performClick();visible(a,"手动填写连接配置")!!.performClick()}
  waitForIdleSync();runOnMainSync{scroll().scrollTo(0,180);savedScroll=scroll().scrollY}
  a=recreateAndResume(a)
  runOnMainSync{check(visible(a,"服务器地址")!=null){"Expanded setup survives recreation so its scroll destination still exists"};check(scroll().scrollY==savedScroll){"Setup scroll survives recreation"};a.finish()}
  out.putString("stream","PASS bottom navigation / fresh and configured entry / mandatory checks visible / manual and recommended collapsed / essential actions / recreation / feedback preserves page / fixed navigation\n");finish(Activity.RESULT_OK,out)
 }catch(t:Throwable){out.putString("stream","FAIL "+t.stackTraceToString());finish(Activity.RESULT_CANCELED,out)}}
}
