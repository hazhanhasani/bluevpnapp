package com.v2ray.ang.ui

import android.content.res.ColorStateList
import android.graphics.Typeface
import android.graphics.drawable.ColorDrawable
import android.os.Bundle
import android.util.Base64
import android.os.Handler
import android.os.Looper
import android.view.Gravity
import android.view.View
import android.view.inputmethod.EditorInfo
import android.widget.ArrayAdapter
import android.widget.EditText
import android.widget.FrameLayout
import android.widget.LinearLayout
import android.widget.ScrollView
import android.widget.Spinner
import android.widget.TextView
import android.widget.Toast
import androidx.activity.result.contract.ActivityResultContracts
import androidx.lifecycle.lifecycleScope
import com.google.android.material.button.MaterialButton
import com.google.android.material.card.MaterialCardView
import com.v2ray.ang.bluevpn.BlueVpnAccountManager
import com.v2ray.ang.bluevpn.BlueVpnPalette
import com.v2ray.ang.bluevpn.BlueVpnTheme
import com.v2ray.ang.bluevpn.BlueVpnSupportNotifications
import com.v2ray.ang.bluevpn.BlueVpnUiGuard
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import org.json.JSONArray
import org.json.JSONObject

class BlueVpnSupportActivity : HelperBaseActivity() {
    private lateinit var palette: BlueVpnPalette
    private lateinit var conversationBox: LinearLayout
    private lateinit var messagesBox: LinearLayout
    private lateinit var messageInput: EditText
    private lateinit var departmentSpinner: Spinner
    private lateinit var newChatBox: LinearLayout
    private lateinit var statusText: TextView
    private val attachmentPicker = registerForActivityResult(
        ActivityResultContracts.GetContent()
    ) { uri ->
        if (uri != null) uploadAttachment(uri)
    }
    private val handler = Handler(Looper.getMainLooper())
    private var activeConversationId = 0
    private var lastMessageId = 0
    private var departmentIds = mutableListOf<Int>()
    private var resumed = false

    private val poll = object : Runnable {
        override fun run() {
            if (!resumed || activeConversationId <= 0) return
            loadMessages(incremental = true)
            handler.postDelayed(this, 4500L)
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        window.setWindowAnimations(0)
        palette = BlueVpnTheme.palette(this)
        window.setBackgroundDrawable(ColorDrawable(palette.background))
        BlueVpnTheme.applySystemBars(this)
        setContentView(createScreen())
        BlueVpnSupportNotifications.schedule(this)
        if (BlueVpnAccountManager.hasSession(this)) {
            loadDepartments()
            val requestedConversation = intent.getIntExtra("conversation_id", 0)
            if (requestedConversation > 0) {
                activeConversationId = requestedConversation
                loadConversations()
                loadMessages(false)
            } else {
                loadConversations()
            }
        } else {
            statusText.text = "برای گفت‌وگو با پشتیبانی ابتدا وارد حساب BlueVPN شوید."
            newChatBox.visibility = View.GONE
        }
    }

    override fun onResume() {
        super.onResume()
        resumed = true
        if (activeConversationId > 0) {
            handler.removeCallbacks(poll)
            handler.postDelayed(poll, 1200L)
        }
    }

    override fun onPause() {
        resumed = false
        handler.removeCallbacks(poll)
        super.onPause()
    }

    private fun createScreen(): View {
        palette = BlueVpnTheme.palette(this)
        val frame = FrameLayout(this).apply { setBackgroundColor(palette.background) }
        val root = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(dp(16), dp(12), dp(16), dp(14))
            layoutDirection = View.LAYOUT_DIRECTION_RTL
        }
        frame.addView(root, FrameLayout.LayoutParams(-1, -1))

        val header = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER_VERTICAL
        }
        val back = MaterialButton(this).apply {
            text = "بازگشت"
            textSize = 12f
            isAllCaps = false
            insetTop = 0
            insetBottom = 0
            setTextColor(palette.textPrimary)
            backgroundTintList = ColorStateList.valueOf(palette.surfaceStrong)
            strokeColor = ColorStateList.valueOf(palette.stroke)
            strokeWidth = dp(1)
            cornerRadius = dp(16)
            BlueVpnUiGuard.bind(this) { finish() }
        }
        val title = TextView(this).apply {
            text = "پشتیبانی آنلاین"
            textSize = 24f
            gravity = Gravity.END or Gravity.CENTER_VERTICAL
            setTextColor(palette.textPrimary)
            setTypeface(typeface, Typeface.BOLD)
        }
        header.addView(back, LinearLayout.LayoutParams(dp(90), dp(46)))
        header.addView(title, LinearLayout.LayoutParams(0, dp(50), 1f))
        root.addView(header)

        statusText = TextView(this).apply {
            text = "پیام‌ها مستقیماً برای تیم پشتیبانی BlueVPN ارسال می‌شوند."
            textSize = 12f
            setTextColor(palette.textMuted)
            gravity = Gravity.END
            setPadding(dp(4), dp(6), dp(4), dp(10))
        }
        root.addView(statusText)

        newChatBox = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER_VERTICAL
        }
        departmentSpinner = Spinner(this)
        newChatBox.addView(departmentSpinner, LinearLayout.LayoutParams(0, dp(48), 1f))
        val newChat = MaterialButton(this).apply {
            text = "گفتگوی جدید"
            isAllCaps = false
            cornerRadius = dp(16)
            BlueVpnUiGuard.bind(this) { beginNewConversation() }
        }
        newChatBox.addView(newChat, LinearLayout.LayoutParams(dp(132), dp(48)))
        root.addView(newChatBox)

        val conversationsScroll = ScrollView(this).apply {
            isHorizontalScrollBarEnabled = false
            overScrollMode = View.OVER_SCROLL_NEVER
        }
        conversationBox = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.END
        }
        conversationsScroll.addView(conversationBox)
        root.addView(conversationsScroll, LinearLayout.LayoutParams(-1, dp(74)))

        val messageScroll = ScrollView(this).apply {
            isFillViewport = true
            overScrollMode = View.OVER_SCROLL_NEVER
        }
        messagesBox = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(0, dp(8), 0, dp(8))
        }
        messageScroll.addView(messagesBox)
        root.addView(messageScroll, LinearLayout.LayoutParams(-1, 0, 1f))

        val composer = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.BOTTOM
        }
        messageInput = EditText(this).apply {
            hint = "پیام خود را بنویسید…"
            minLines = 1
            maxLines = 4
            imeOptions = EditorInfo.IME_ACTION_SEND
            setTextColor(palette.textPrimary)
            setHintTextColor(palette.textMuted)
            backgroundTintList = ColorStateList.valueOf(palette.accent)
            setPadding(dp(10), dp(8), dp(10), dp(8))
            setOnEditorActionListener { _, actionId, _ ->
                if (actionId == EditorInfo.IME_ACTION_SEND) {
                    sendMessage()
                    true
                } else false
            }
        }
        val attach = MaterialButton(this).apply {
            text = "فایل"
            isAllCaps = false
            cornerRadius = dp(18)
            BlueVpnUiGuard.bind(this) {
                if (activeConversationId <= 0) {
                    Toast.makeText(
                        this@BlueVpnSupportActivity,
                        "ابتدا گفتگو را ایجاد کنید",
                        Toast.LENGTH_SHORT,
                    ).show()
                } else {
                    attachmentPicker.launch("*/*")
                }
            }
        }
        composer.addView(attach, LinearLayout.LayoutParams(dp(78), dp(52)))
        composer.addView(messageInput, LinearLayout.LayoutParams(0, -2, 1f))
        val send = MaterialButton(this).apply {
            text = "ارسال"
            isAllCaps = false
            cornerRadius = dp(18)
            BlueVpnUiGuard.bind(this) { sendMessage() }
        }
        composer.addView(send, LinearLayout.LayoutParams(dp(86), dp(52)))
        root.addView(composer)

        return frame
    }

    private fun loadDepartments() {
        lifecycleScope.launch(Dispatchers.IO) {
            val result = BlueVpnAccountManager.supportRequest(
                this@BlueVpnSupportActivity,
                "GET",
                "/api/v1/support/departments",
            )
            withContext(Dispatchers.Main) {
                result.onSuccess { json ->
                    val rows = json.optJSONArray("departments") ?: JSONArray()
                    departmentIds.clear()
                    val names = mutableListOf<String>()
                    for (i in 0 until rows.length()) {
                        val row = rows.optJSONObject(i) ?: continue
                        departmentIds += row.optInt("id")
                        names += row.optString("name", "پشتیبانی")
                    }
                    departmentSpinner.adapter = ArrayAdapter(
                        this@BlueVpnSupportActivity,
                        android.R.layout.simple_spinner_dropdown_item,
                        names,
                    )
                }.onFailure {
                    statusText.text = "دریافت بخش‌های پشتیبانی ناموفق بود."
                }
            }
        }
    }

    private fun loadConversations(selectNewest: Boolean = false) {
        lifecycleScope.launch(Dispatchers.IO) {
            val result = BlueVpnAccountManager.supportRequest(
                this@BlueVpnSupportActivity,
                "GET",
                "/api/v1/support/conversations",
            )
            withContext(Dispatchers.Main) {
                result.onSuccess { json ->
                    conversationBox.removeAllViews()
                    val rows = json.optJSONArray("conversations") ?: JSONArray()
                    for (i in 0 until rows.length()) {
                        val row = rows.optJSONObject(i) ?: continue
                        addConversationChip(row)
                    }
                    if (selectNewest && rows.length() > 0) {
                        openConversation(rows.optJSONObject(0)?.optInt("id") ?: 0)
                    }
                }
            }
        }
    }

    private fun addConversationChip(row: JSONObject) {
        val id = row.optInt("id")
        val department = row.optJSONObject("department")?.optString("name").orEmpty()
        val status = statusLabel(row.optString("status"))
        val unread = row.optInt("unread")
        val button = MaterialButton(this).apply {
            text = buildString {
                append(if (department.isBlank()) "پشتیبانی" else department)
                append(" • ")
                append(status)
                if (unread > 0) append(" ($unread)")
            }
            textSize = 11f
            isAllCaps = false
            cornerRadius = dp(16)
            insetTop = 0
            insetBottom = 0
            backgroundTintList = ColorStateList.valueOf(
                if (id == activeConversationId) palette.accent else palette.surfaceStrong
            )
            setTextColor(
                if (id == activeConversationId) palette.background else palette.textPrimary
            )
            BlueVpnUiGuard.bind(this) { openConversation(id) }
        }
        conversationBox.addView(button, LinearLayout.LayoutParams(-2, dp(48)).apply {
            marginEnd = dp(8)
        })
    }

    private fun beginNewConversation() {
        if (departmentIds.isEmpty()) {
            Toast.makeText(this, "ابتدا بخش پشتیبانی را انتخاب کنید", Toast.LENGTH_SHORT).show()
            return
        }
        val departmentId = departmentIds.getOrNull(departmentSpinner.selectedItemPosition) ?: return
        val firstMessage = messageInput.text?.toString()?.trim().orEmpty()
        if (firstMessage.isBlank()) {
            messageInput.error = "پیام اولیه را بنویسید"
            return
        }
        messageInput.isEnabled = false
        lifecycleScope.launch(Dispatchers.IO) {
            val body = JSONObject()
                .put("department_id", departmentId)
                .put("subject", firstMessage.take(70))
                .put("message", firstMessage)
            val result = BlueVpnAccountManager.supportRequest(
                this@BlueVpnSupportActivity,
                "POST",
                "/api/v1/support/conversations",
                body,
            )
            withContext(Dispatchers.Main) {
                messageInput.isEnabled = true
                result.onSuccess { json ->
                    messageInput.setText("")
                    val id = json.optJSONObject("conversation")?.optInt("id") ?: 0
                    if (id > 0) {
                        activeConversationId = id
                        lastMessageId = 0
                        loadConversations()
                        loadMessages(false)
                    }
                }.onFailure {
                    Toast.makeText(
                        this@BlueVpnSupportActivity,
                        "ایجاد گفتگو ناموفق بود",
                        Toast.LENGTH_SHORT,
                    ).show()
                }
            }
        }
    }

    private fun openConversation(id: Int) {
        if (id <= 0) return
        activeConversationId = id
        lastMessageId = 0
        messagesBox.removeAllViews()
        loadConversations()
        loadMessages(false)
        handler.removeCallbacks(poll)
        handler.postDelayed(poll, 4500L)
    }

    private fun loadMessages(incremental: Boolean) {
        val cid = activeConversationId
        if (cid <= 0) return
        val after = if (incremental) lastMessageId else 0
        lifecycleScope.launch(Dispatchers.IO) {
            val result = BlueVpnAccountManager.supportRequest(
                this@BlueVpnSupportActivity,
                "GET",
                "/api/v1/support/conversations/$cid/messages?after_id=$after",
            )
            withContext(Dispatchers.Main) {
                result.onSuccess { json ->
                    val conv = json.optJSONObject("conversation")
                    conv?.let {
                        val op = it.optJSONObject("operator")
                        statusText.text = if (op != null) {
                            "${op.optString("display_name", "پشتیبانی")} • ${statusLabel(it.optString("status"))}"
                        } else {
                            "در صف ${it.optJSONObject("department")?.optString("name", "پشتیبانی")} • ${statusLabel(it.optString("status"))}"
                        }
                    }
                    val rows = json.optJSONArray("messages") ?: JSONArray()
                    if (!incremental) messagesBox.removeAllViews()
                    for (i in 0 until rows.length()) {
                        val row = rows.optJSONObject(i) ?: continue
                        lastMessageId = maxOf(lastMessageId, row.optInt("id"))
                        addMessageBubble(row)
                    }
                    loadConversations()
                }
            }
        }
    }

    private fun addMessageBubble(row: JSONObject) {
        val customer = row.optString("sender") == "customer"
        val card = MaterialCardView(this).apply {
            radius = dp(18).toFloat()
            cardElevation = 0f
            setCardBackgroundColor(
                if (customer) palette.accent else palette.surfaceStrong
            )
            strokeWidth = 0
        }
        val attachments = row.optJSONArray("attachments") ?: JSONArray()
        val attachmentLabel = if (attachments.length() > 0) {
            val item = attachments.optJSONObject(0)
            "\n📎 " + (item?.optString("name").orEmpty().ifBlank { "فایل پیوست" })
        } else {
            ""
        }
        val text = TextView(this).apply {
            this.text = row.optString("body") + attachmentLabel
            textSize = 14f
            setTextColor(if (customer) palette.background else palette.textPrimary)
            setPadding(dp(13), dp(10), dp(13), dp(10))
            gravity = Gravity.END
        }
        card.addView(text)
        val wrap = LinearLayout(this).apply {
            gravity = if (customer) Gravity.END else Gravity.START
            addView(card, LinearLayout.LayoutParams(0, -2, 0.82f))
        }
        messagesBox.addView(wrap, LinearLayout.LayoutParams(-1, -2).apply {
            topMargin = dp(5)
        })
    }

    private fun uploadAttachment(uri: android.net.Uri) {
        val cid = activeConversationId
        if (cid <= 0) return
        lifecycleScope.launch(Dispatchers.IO) {
            val resolver = contentResolver
            val mime = resolver.getType(uri)?.lowercase().orEmpty()
            val allowed = setOf(
                "image/jpeg",
                "image/png",
                "image/webp",
                "application/pdf",
                "text/plain",
                "application/zip",
            )
            if (mime !in allowed) {
                withContext(Dispatchers.Main) {
                    Toast.makeText(
                        this@BlueVpnSupportActivity,
                        "نوع فایل پشتیبانی نمی‌شود",
                        Toast.LENGTH_SHORT,
                    ).show()
                }
                return@launch
            }

            val bytes = runCatching {
                resolver.openInputStream(uri)?.use { input ->
                    val out = java.io.ByteArrayOutputStream()
                    val buffer = ByteArray(16 * 1024)
                    var total = 0
                    while (true) {
                        val read = input.read(buffer)
                        if (read <= 0) break
                        total += read
                        if (total > 4 * 1024 * 1024) {
                            throw IllegalArgumentException("ATTACHMENT_TOO_LARGE")
                        }
                        out.write(buffer, 0, read)
                    }
                    out.toByteArray()
                }
            }.getOrElse { error ->
                withContext(Dispatchers.Main) {
                    Toast.makeText(
                        this@BlueVpnSupportActivity,
                        if (error.message == "ATTACHMENT_TOO_LARGE") "حداکثر حجم فایل ۴ مگابایت است" else "خواندن فایل ناموفق بود",
                        Toast.LENGTH_SHORT,
                    ).show()
                }
                return@launch
            } ?: return@launch

            val name = uri.lastPathSegment
                ?.substringAfterLast('/')
                ?.takeLast(120)
                ?.ifBlank { "attachment" }
                ?: "attachment"

            val body = JSONObject()
                .put("name", name)
                .put("mime", mime)
                .put("data_base64", Base64.encodeToString(bytes, Base64.NO_WRAP))

            val result = BlueVpnAccountManager.supportRequest(
                this@BlueVpnSupportActivity,
                "POST",
                "/api/v1/support/conversations/$cid/attachments",
                body,
            )
            withContext(Dispatchers.Main) {
                result.onSuccess {
                    Toast.makeText(
                        this@BlueVpnSupportActivity,
                        "فایل ارسال شد",
                        Toast.LENGTH_SHORT,
                    ).show()
                    loadMessages(true)
                }.onFailure { error ->
                    val message = if (error.message?.contains("ATTACHMENT_TOO_LARGE") == true) {
                        "حداکثر حجم فایل ۴ مگابایت است"
                    } else {
                        "ارسال فایل ناموفق بود"
                    }
                    Toast.makeText(
                        this@BlueVpnSupportActivity,
                        message,
                        Toast.LENGTH_SHORT,
                    ).show()
                }
            }
        }
    }

    private fun sendMessage() {
        val cid = activeConversationId
        val value = messageInput.text?.toString()?.trim().orEmpty()
        if (cid <= 0) {
            beginNewConversation()
            return
        }
        if (value.isBlank()) return
        messageInput.setText("")
        lifecycleScope.launch(Dispatchers.IO) {
            val result = BlueVpnAccountManager.supportRequest(
                this@BlueVpnSupportActivity,
                "POST",
                "/api/v1/support/conversations/$cid/messages",
                JSONObject().put("message", value),
            )
            withContext(Dispatchers.Main) {
                result.onSuccess { loadMessages(true) }
                    .onFailure {
                        messageInput.setText(value)
                        Toast.makeText(
                            this@BlueVpnSupportActivity,
                            "ارسال پیام ناموفق بود",
                            Toast.LENGTH_SHORT,
                        ).show()
                    }
            }
        }
    }

    private fun statusLabel(value: String): String = when (value) {
        "waiting" -> "در انتظار اپراتور"
        "open" -> "در حال پاسخ"
        "pending_customer" -> "منتظر پاسخ شما"
        "resolved" -> "حل‌شده"
        "closed" -> "بسته"
        else -> "فعال"
    }

    private fun dp(value: Int): Int =
        (value * resources.displayMetrics.density).toInt()
}
