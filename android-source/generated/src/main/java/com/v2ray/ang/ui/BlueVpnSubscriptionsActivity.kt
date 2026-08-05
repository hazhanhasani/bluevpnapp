package com.v2ray.ang.ui
import android.content.Intent
import android.content.res.ColorStateList
import android.graphics.Color
import android.graphics.Typeface
import android.graphics.drawable.GradientDrawable
import android.net.Uri
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.text.InputType
import android.view.Gravity
import android.view.View
import android.view.inputmethod.EditorInfo
import android.widget.EditText
import android.widget.LinearLayout
import android.widget.ScrollView
import android.widget.TextView
import android.widget.Toast
import androidx.lifecycle.lifecycleScope
import com.google.android.material.button.MaterialButton
import com.google.android.material.card.MaterialCardView
import com.v2ray.ang.bluevpn.BlueVpnAccountManager
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import org.json.JSONObject
import java.text.NumberFormat
import java.util.Locale
class BlueVpnSubscriptionsActivity:HelperBaseActivity(){
 private lateinit var content:LinearLayout;private lateinit var status:TextView;private val handler=Handler(Looper.getMainLooper());private var busy=false;private var firstResume=true
 private val poll=object:Runnable{override fun run(){val id=BlueVpnAccountManager.pendingOrder(this@BlueVpnSubscriptionsActivity);if(id.isNotBlank()){checkOrder(id);handler.postDelayed(this,4000)}}}
 override fun onCreate(b:Bundle?){super.onCreate(b);window.statusBarColor=Color.parseColor("#07152F");window.navigationBarColor=Color.parseColor("#07152F");setContentView(screen());render()}
 override fun onResume(){super.onResume();if(firstResume){firstResume=false}else{render()};handler.removeCallbacks(poll);handler.post(poll)}
 override fun onPause(){handler.removeCallbacks(poll);super.onPause()}
 private fun screen():View{val root=LinearLayout(this).apply{orientation=LinearLayout.VERTICAL;setPadding(dp(18),dp(16),dp(18),dp(22));setBackgroundColor(Color.parseColor("#071A39"));layoutDirection=View.LAYOUT_DIRECTION_RTL};val h=LinearLayout(this).apply{orientation=LinearLayout.HORIZONTAL;gravity=Gravity.CENTER_VERTICAL};val back=button("بازگشت","#173B70").apply{setOnClickListener{finish()}};val title=TextView(this).apply{text="حساب و اشتراک";textSize=24f;setTextColor(Color.WHITE);setTypeface(typeface,Typeface.BOLD);gravity=Gravity.END};h.addView(back,LinearLayout.LayoutParams(dp(92),dp(48)));h.addView(title,LinearLayout.LayoutParams(0,dp(52),1f));root.addView(h);status=TextView(this).apply{textSize=12.5f;setTextColor(Color.parseColor("#9FB7D9"));gravity=Gravity.CENTER;setPadding(0,dp(7),0,dp(10))};root.addView(status);content=LinearLayout(this).apply{orientation=LinearLayout.VERTICAL};root.addView(ScrollView(this).apply{isFillViewport=true;addView(content)},LinearLayout.LayoutParams(-1,0,1f));return root}
 private fun render(){content.removeAllViews();if(BlueVpnAccountManager.hasSession(this))account() else auth()}
 private fun auth(){
  status.text="ورود امن و همگام‌سازی خودکار BlueVPN"

  val hero=MaterialCardView(this).apply{
   radius=dp(28).toFloat()
   cardElevation=0f
   strokeWidth=dp(1)
   strokeColor=Color.parseColor("#397FD8")
   setCardBackgroundColor(Color.TRANSPARENT)
  }

  val heroBox=LinearLayout(this).apply{
   orientation=LinearLayout.VERTICAL
   gravity=Gravity.CENTER
   setPadding(dp(20),dp(20),dp(20),dp(20))
   background=GradientDrawable(
    GradientDrawable.Orientation.TL_BR,
    intArrayOf(
     Color.parseColor("#123B7A"),
     Color.parseColor("#0A2148"),
     Color.parseColor("#071A39")
    )
   ).apply{cornerRadius=dp(28).toFloat()}
  }

  heroBox.addView(TextView(this).apply{
   text="B"
   textSize=30f
   gravity=Gravity.CENTER
   setTextColor(Color.WHITE)
   setTypeface(typeface,Typeface.BOLD)
   background=GradientDrawable(
    GradientDrawable.Orientation.TL_BR,
    intArrayOf(
     Color.parseColor("#5A9DFF"),
     Color.parseColor("#176DFF")
    )
   ).apply{
    shape=GradientDrawable.OVAL
    setStroke(dp(2),Color.parseColor("#89BCFF"))
   }
  },LinearLayout.LayoutParams(dp(64),dp(64)))

  heroBox.addView(TextView(this).apply{
   text="به BlueVPN خوش آمدید"
   textSize=23f
   gravity=Gravity.CENTER
   setTextColor(Color.WHITE)
   setTypeface(typeface,Typeface.BOLD)
   setPadding(0,dp(16),0,0)
  })

  heroBox.addView(TextView(this).apply{
   text="اتصال ساده، انتخاب هوشمند و دسترسی یکپارچه به همه لوکیشن‌ها"
   textSize=12.5f
   gravity=Gravity.CENTER
   setTextColor(Color.parseColor("#BBD3F5"))
   setPadding(dp(8),dp(7),dp(8),dp(15))
  })

  val benefits=LinearLayout(this).apply{
   orientation=LinearLayout.HORIZONTAL
   gravity=Gravity.CENTER
  }
  benefits.addView(authBadge("حساب","ورود امن"),LinearLayout.LayoutParams(0,dp(42),1f).apply{marginEnd=dp(5)})
  benefits.addView(authBadge("شبکه","اتصال هوشمند"),LinearLayout.LayoutParams(0,dp(42),1f).apply{marginStart=dp(5)})
  heroBox.addView(benefits,LinearLayout.LayoutParams(-1,dp(42)))
  hero.addView(heroBox)
  content.addView(hero,LinearLayout.LayoutParams(-1,-2).apply{bottomMargin=dp(14)})

  val form=card()
  val box=LinearLayout(this).apply{
   orientation=LinearLayout.VERTICAL
   setPadding(dp(18),dp(18),dp(18),dp(18))
  }

  box.addView(TextView(this).apply{
   text="ورود یا ساخت حساب"
   textSize=18f
   setTextColor(Color.WHITE)
   setTypeface(typeface,Typeface.BOLD)
   gravity=Gravity.END
  })

  box.addView(TextView(this).apply{
   text="اطلاعات حساب شما رمزگذاری‌شده نگهداری می‌شود."
   textSize=11.5f
   setTextColor(Color.parseColor("#8FAAD0"))
   gravity=Gravity.END
   setPadding(0,dp(5),0,dp(14))
  })

  val email=authField(
   hintText="ایمیل",
   passwordField=false
  ).apply{
   imeOptions=EditorInfo.IME_ACTION_NEXT
  }
  val pass=authField(
   hintText="رمز عبور؛ حداقل ۸ کاراکتر",
   passwordField=true
  ).apply{
   imeOptions=EditorInfo.IME_ACTION_DONE
   setOnEditorActionListener{_,actionId,_->
    if(actionId==EditorInfo.IME_ACTION_DONE){
     authCall(email.text.toString(),text.toString(),false)
     true
    }else false
   }
  }

  box.addView(email,LinearLayout.LayoutParams(-1,dp(58)))
  box.addView(pass,LinearLayout.LayoutParams(-1,dp(58)).apply{topMargin=dp(10)})

  box.addView(
   button("ورود به BlueVPN","#1676FF").apply{
    textSize=14f
    setTypeface(typeface,Typeface.BOLD)
    setOnClickListener{
     authCall(
      email.text.toString(),
      pass.text.toString(),
      false
     )
    }
   },
   LinearLayout.LayoutParams(-1,dp(52)).apply{topMargin=dp(16)}
  )

  val divider=LinearLayout(this).apply{
   orientation=LinearLayout.HORIZONTAL
   gravity=Gravity.CENTER
   setPadding(0,dp(12),0,dp(10))
  }
  divider.addView(View(this).apply{setBackgroundColor(Color.parseColor("#294D7D"))},LinearLayout.LayoutParams(0,dp(1),1f))
  divider.addView(TextView(this).apply{
   text="  حساب ندارید؟  "
   textSize=11.5f
   setTextColor(Color.parseColor("#8FAAD0"))
  })
  divider.addView(View(this).apply{setBackgroundColor(Color.parseColor("#294D7D"))},LinearLayout.LayoutParams(0,dp(1),1f))
  box.addView(divider)

  box.addView(
   button("ساخت حساب جدید","#173B70").apply{
    strokeWidth=dp(1)
    strokeColor=ColorStateList.valueOf(Color.parseColor("#4B8EE5"))
    setOnClickListener{
     authCall(
      email.text.toString(),
      pass.text.toString(),
      true
     )
    }
   },
   LinearLayout.LayoutParams(-1,dp(50))
  )

  box.addView(TextView(this).apply{
   text="ورود شما حفظ می‌شود و همگام‌سازی فقط هنگام اجرای تازه برنامه انجام می‌شود."
   textSize=10.5f
   gravity=Gravity.CENTER
   setTextColor(Color.parseColor("#6F8EB8"))
   setPadding(0,dp(13),0,0)
  })

  form.addView(box)
  content.addView(form)
 }
 private fun account(){
  val account=BlueVpnAccountManager.snapshot(this)
  status.text=
   if(account.subscriptionActive){
    "حساب شما آماده اتصال است"
   }else{
    "برای ادامه یک پلن انتخاب کنید"
   }

  val card=MaterialCardView(this).apply{
   radius=dp(24).toFloat()
   cardElevation=0f
   strokeWidth=dp(1)
   strokeColor=Color.parseColor(
    if(account.subscriptionActive)"#2A9C77" else "#315F99"
   )
   setCardBackgroundColor(Color.TRANSPARENT)
  }

  val box=LinearLayout(this).apply{
   orientation=LinearLayout.VERTICAL
   setPadding(dp(18),dp(18),dp(18),dp(18))
   background=GradientDrawable(
    GradientDrawable.Orientation.TL_BR,
    intArrayOf(
     if(account.subscriptionActive){
      Color.parseColor("#124A4B")
     }else{
      Color.parseColor("#173C70")
     },
     Color.parseColor("#0B2855"),
     Color.parseColor("#081C3C")
    )
   ).apply{cornerRadius=dp(24).toFloat()}
  }

  val top=LinearLayout(this).apply{
   orientation=LinearLayout.HORIZONTAL
   gravity=Gravity.CENTER_VERTICAL
  }

  top.addView(TextView(this).apply{
   text=if(account.subscriptionActive)"✓" else "!"
   textSize=22f
   gravity=Gravity.CENTER
   setTextColor(Color.WHITE)
   background=GradientDrawable().apply{
    shape=GradientDrawable.OVAL
    setColor(
     if(account.subscriptionActive){
      Color.parseColor("#18B67A")
     }else{
      Color.parseColor("#FFB44A")
     }
    )
   }
  },LinearLayout.LayoutParams(dp(48),dp(48)))

  val identity=LinearLayout(this).apply{
   orientation=LinearLayout.VERTICAL
   setPadding(dp(12),0,dp(12),0)
  }
  identity.addView(TextView(this).apply{
   text=if(account.subscriptionActive){
    "اشتراک Premium فعال"
   }else{
    "حساب فعال • بدون اشتراک"
   }
   textSize=17f
   setTextColor(Color.WHITE)
   setTypeface(typeface,Typeface.BOLD)
  })
  identity.addView(TextView(this).apply{
   text=account.email
   textSize=11.5f
   setTextColor(Color.parseColor("#AFC5E5"))
   setPadding(0,dp(4),0,0)
  })
  top.addView(identity,LinearLayout.LayoutParams(0,-2,1f))
  box.addView(top)

  val remaining=if(account.dataLimitBytes<=0L){
   "نامحدود"
  }else{
   bytes(
    (
     account.dataLimitBytes-account.usedTrafficBytes
    ).coerceAtLeast(0L)
   )
  }

  val metrics=LinearLayout(this).apply{
   orientation=LinearLayout.HORIZONTAL
   setPadding(0,dp(16),0,dp(8))
  }
  metrics.addView(accountMetric(
   "حجم باقی‌مانده",
   if(account.subscriptionActive)remaining else "—"
  ),LinearLayout.LayoutParams(0,dp(78),1f))
  metrics.addView(accountMetric(
   "دستگاه مجاز",
   "${account.deviceLimit}"
  ),LinearLayout.LayoutParams(0,dp(78),1f).apply{
   marginStart=dp(8)
  })
  box.addView(metrics)

  box.addView(TextView(this).apply{
   text=if(account.subscriptionActive){
    "اعتبار: ${account.expire?:"نامحدود"}"
   }else{
    "پس از انتخاب پلن، کانفیگ‌ها خودکار به حساب اضافه می‌شوند."
   }
   textSize=12f
   gravity=Gravity.CENTER
   setTextColor(Color.parseColor("#B8D1F1"))
   setPadding(0,dp(5),0,dp(7))
  })

  box.addView(button("خروج از حساب","#6A2940").apply{
   setOnClickListener{
    BlueVpnAccountManager.logout(
     this@BlueVpnSubscriptionsActivity
    )
    setResult(RESULT_CANCELED)
    render()
   }
  },LinearLayout.LayoutParams(-1,dp(46)).apply{
   topMargin=dp(8)
  })

  card.addView(box)
  content.addView(card)
  loadPlans()
 }
private fun loadPlans(){
 if(!BlueVpnAccountManager.hasSession(this)){
  render()
  return
 }

 lifecycleScope.launch(Dispatchers.IO){
  val r=BlueVpnAccountManager.plans(
   this@BlueVpnSubscriptionsActivity
  )

  withContext(Dispatchers.Main){
   r.onSuccess{arr->
    if(!BlueVpnAccountManager.hasSession(
      this@BlueVpnSubscriptionsActivity
     )){
     render()
     return@onSuccess
    }

    for(i in 0 until arr.length()){
     plan(arr.getJSONObject(i))
    }
   }.onFailure{
    if(!BlueVpnAccountManager.hasSession(
      this@BlueVpnSubscriptionsActivity
     )){
     render()
    }else{
     status.text=it.message?:"دریافت پلن ناموفق بود"
    }
   }
  }
 }
}
 private fun plan(p:JSONObject){
  val card=MaterialCardView(this).apply{
   radius=dp(22).toFloat()
   cardElevation=0f
   setCardBackgroundColor(Color.parseColor("#102A55"))
   strokeColor=Color.parseColor("#315F99")
   strokeWidth=dp(1)
  }

  val box=LinearLayout(this).apply{
   orientation=LinearLayout.VERTICAL
   setPadding(dp(17),dp(16),dp(17),dp(16))
  }

  val header=LinearLayout(this).apply{
   orientation=LinearLayout.HORIZONTAL
   gravity=Gravity.CENTER_VERTICAL
  }

  val titleBox=LinearLayout(this).apply{
   orientation=LinearLayout.VERTICAL
  }
  titleBox.addView(TextView(this).apply{
   text=p.optString("title")
   textSize=18f
   setTextColor(Color.WHITE)
   setTypeface(typeface,Typeface.BOLD)
  })
  titleBox.addView(TextView(this).apply{
   text=p.optString("description")
   textSize=11.5f
   setTextColor(Color.parseColor("#9FB7D9"))
   setPadding(0,dp(5),0,0)
  })
  header.addView(titleBox,LinearLayout.LayoutParams(0,-2,1f))
  header.addView(TextView(this).apply{
   text="PREMIUM"
   textSize=9.5f
   gravity=Gravity.CENTER
   setTextColor(Color.parseColor("#E7DDFF"))
   background=GradientDrawable(
    GradientDrawable.Orientation.TL_BR,
    intArrayOf(
     Color.parseColor("#7656D8"),
     Color.parseColor("#4D3B9D")
    )
   ).apply{cornerRadius=dp(12).toFloat()}
  },LinearLayout.LayoutParams(dp(76),dp(34)))
  box.addView(header)

  val days=p.optInt("duration_days")
  val gb=p.optInt("data_limit_gb")
  val features=LinearLayout(this).apply{
   orientation=LinearLayout.HORIZONTAL
   gravity=Gravity.CENTER
   setPadding(0,dp(14),0,0)
  }
  features.addView(planBadge(
   if(days==0)"زمان نامحدود" else "$days روز"
  ),LinearLayout.LayoutParams(0,dp(42),1f))
  features.addView(planBadge(
   if(gb==0)"حجم نامحدود" else "$gb گیگ"
  ),LinearLayout.LayoutParams(0,dp(42),1f).apply{
   marginStart=dp(6)
  })
  features.addView(planBadge(
   "${p.optInt("device_limit",1)} دستگاه"
  ),LinearLayout.LayoutParams(0,dp(42),1f).apply{
   marginStart=dp(6)
  })
  box.addView(features)

  val price=NumberFormat.getNumberInstance(
   Locale("fa")
  ).format(p.optInt("price_toman"))

  box.addView(button(
   "$price تومان • پرداخت امن با BluePay",
   "#1676FF"
  ).apply{
   textSize=13.5f
   setTypeface(typeface,Typeface.BOLD)
   setOnClickListener{
    buy(p.optInt("id"))
   }
  },LinearLayout.LayoutParams(-1,dp(52)).apply{
   topMargin=dp(14)
  })

  card.addView(box)
  content.addView(card,LinearLayout.LayoutParams(-1,-2).apply{
   topMargin=dp(11)
  })
 }
 private fun authCall(e:String,p:String,reg:Boolean){if(busy)return;if(e.isBlank()||p.length<8){Toast.makeText(this,"ایمیل و رمز معتبر وارد کنید",Toast.LENGTH_SHORT).show();return};busy=true;status.text="در حال بررسی...";lifecycleScope.launch(Dispatchers.IO){val r=BlueVpnAccountManager.authenticate(this@BlueVpnSubscriptionsActivity,e,p,reg);withContext(Dispatchers.Main){busy=false;r.onSuccess{setResult(RESULT_OK);render()}.onFailure{status.text=it.message?:"ورود ناموفق"}}}}
 private fun sync(force:Boolean){if(busy)return;busy=true;lifecycleScope.launch(Dispatchers.IO){val r=BlueVpnAccountManager.sync(this@BlueVpnSubscriptionsActivity,force);withContext(Dispatchers.Main){busy=false;r.onFailure{if(!BlueVpnAccountManager.hasSession(this@BlueVpnSubscriptionsActivity))render() else status.text=it.message?:"خطای همگام‌سازی"};render()}}}
 private fun buy(planId:Int){status.text="در حال ساخت فاکتور...";lifecycleScope.launch(Dispatchers.IO){val r=BlueVpnAccountManager.createOrder(this@BlueVpnSubscriptionsActivity,planId);withContext(Dispatchers.Main){r.onSuccess{o->val id=o.optString("id");BlueVpnAccountManager.setPendingOrder(this@BlueVpnSubscriptionsActivity,id);status.text="پس از پرداخت به برنامه برگردید";startActivity(Intent(Intent.ACTION_VIEW,Uri.parse(o.optString("payment_url"))))}.onFailure{status.text=it.message?:"ساخت فاکتور ناموفق"}}}}
 private fun checkOrder(id:String){lifecycleScope.launch(Dispatchers.IO){val r=BlueVpnAccountManager.order(this@BlueVpnSubscriptionsActivity,id);withContext(Dispatchers.Main){r.onSuccess{o->when(o.optString("status")){"activated"->{BlueVpnAccountManager.clearPendingOrder(this@BlueVpnSubscriptionsActivity);handler.removeCallbacks(poll);sync(true);Toast.makeText(this@BlueVpnSubscriptionsActivity,"اشتراک فعال شد",Toast.LENGTH_LONG).show()}"paid","paid_needs_sync"->status.text="پرداخت تأیید شد؛ فعال‌سازی در حال انجام است";"expired"->{BlueVpnAccountManager.clearPendingOrder(this@BlueVpnSubscriptionsActivity);status.text="مهلت فاکتور تمام شد"};else->status.text="در انتظار تأیید پرداخت..."}}}}}
private fun accountMetric(
 title:String,
 value:String,
)=LinearLayout(this).apply{
 orientation=LinearLayout.VERTICAL
 gravity=Gravity.CENTER
 background=GradientDrawable().apply{
  cornerRadius=dp(15).toFloat()
  setColor(Color.parseColor("#0D2952"))
  setStroke(dp(1),Color.parseColor("#2D609F"))
 }
 addView(TextView(this@BlueVpnSubscriptionsActivity).apply{
  text=title
  textSize=10f
  gravity=Gravity.CENTER
  setTextColor(Color.parseColor("#89A8D1"))
 })
 addView(TextView(this@BlueVpnSubscriptionsActivity).apply{
  text=value
  textSize=15f
  gravity=Gravity.CENTER
  setTextColor(Color.WHITE)
  setTypeface(typeface,Typeface.BOLD)
  setPadding(0,dp(4),0,0)
 })
}
private fun planBadge(value:String)=TextView(this).apply{
 text=value
 textSize=10.5f
 gravity=Gravity.CENTER
 setTextColor(Color.parseColor("#D7E7FF"))
 background=GradientDrawable().apply{
  cornerRadius=dp(13).toFloat()
  setColor(Color.parseColor("#173B6C"))
  setStroke(dp(1),Color.parseColor("#315F99"))
 }
}
private fun authBadge(icon:String,label:String)=TextView(this).apply{
 text="$label • $icon"
 textSize=11.5f
 gravity=Gravity.CENTER
 setTextColor(Color.parseColor("#EAF3FF"))
 background=GradientDrawable().apply{
  cornerRadius=dp(13).toFloat()
  setColor(Color.parseColor("#173C70"))
  setStroke(dp(1),Color.parseColor("#356BA8"))
 }
}
private fun authField(
 hintText:String,
 passwordField:Boolean,
)=EditText(this).apply{
 hint=hintText
 textSize=13f
 isSingleLine=true
 inputType=if(passwordField){
  InputType.TYPE_CLASS_TEXT or
   InputType.TYPE_TEXT_VARIATION_PASSWORD
 }else{
  InputType.TYPE_CLASS_TEXT or
   InputType.TYPE_TEXT_VARIATION_EMAIL_ADDRESS
 }
 setTextColor(Color.WHITE)
 setHintTextColor(Color.parseColor("#7894BD"))
 setPadding(dp(15),0,dp(15),0)
 background=GradientDrawable().apply{
  cornerRadius=dp(15).toFloat()
  setColor(Color.parseColor("#091E42"))
  setStroke(dp(1),Color.parseColor("#2B568D"))
 }
}
private fun card()=MaterialCardView(this).apply{radius=dp(20).toFloat();cardElevation=0f;setCardBackgroundColor(Color.parseColor("#102A55"));strokeColor=Color.parseColor("#214A83");strokeWidth=dp(1)}
 private fun button(t:String,color:String)=MaterialButton(this).apply{text=t;textSize=13f;setTextColor(Color.WHITE);backgroundTintList=ColorStateList.valueOf(Color.parseColor(color));cornerRadius=dp(15);isAllCaps=false}
 private fun bytes(x:Long):String{val u=arrayOf("B","KB","MB","GB","TB");var v=x.toDouble();var i=0;while(v>=1024&&i<u.lastIndex){v/=1024;i++};return String.format(Locale.ROOT,"%.1f %s",v,u[i])}
 private fun dp(x:Int)=(x*resources.displayMetrics.density).toInt()
}
