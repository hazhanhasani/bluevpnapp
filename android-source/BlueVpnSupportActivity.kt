package com.v2ray.ang.ui

import android.content.res.ColorStateList
import android.graphics.Color
import android.graphics.Typeface
import android.graphics.drawable.GradientDrawable
import android.net.Uri
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.text.InputType
import android.util.Base64
import android.view.Gravity
import android.view.View
import android.view.ViewGroup
import android.view.WindowManager
import android.view.inputmethod.EditorInfo
import android.widget.EditText
import android.widget.FrameLayout
import android.widget.HorizontalScrollView
import android.widget.LinearLayout
import android.widget.ScrollView
import android.widget.Space
import android.widget.TextView
import android.widget.Toast
import androidx.activity.result.contract.ActivityResultContracts
import androidx.core.view.ViewCompat
import androidx.core.view.WindowInsetsCompat
import androidx.lifecycle.lifecycleScope
import com.google.android.material.button.MaterialButton
import com.google.android.material.card.MaterialCardView
import com.v2ray.ang.bluevpn.BlueVpnAccountManager
import com.v2ray.ang.bluevpn.BlueVpnPalette
import com.v2ray.ang.bluevpn.BlueVpnPersianDate
import com.v2ray.ang.bluevpn.BlueVpnSupportNotifications
import com.v2ray.ang.bluevpn.BlueVpnTheme
import com.v2ray.ang.bluevpn.BlueVpnUiGuard
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import org.json.JSONArray
import org.json.JSONObject
import java.io.ByteArrayOutputStream
import java.util.Locale
import java.util.UUID

class BlueVpnSupportActivity : HelperBaseActivity() {

    private lateinit var palette: BlueVpnPalette
    private lateinit var root: LinearLayout
    private lateinit var conversationBox: LinearLayout
    private lateinit var messagesBox: LinearLayout
    private lateinit var messagesScroll: ScrollView
    private lateinit var messageInput: EditText
    private lateinit var composer: MaterialCardView
    private lateinit var attachButton: MaterialButton
    private lateinit var sendButton: MaterialButton
    private lateinit var statusText: TextView
    private lateinit var operatorText: TextView
    private lateinit var emptyState: LinearLayout
    private lateinit var departmentSheet: MaterialCardView
    private lateinit var departmentList: LinearLayout
    private lateinit var departmentTitle: TextView
    private lateinit var departmentSubtitle: TextView
    private lateinit var conversationStrip: HorizontalScrollView

    private val handler = Handler(Looper.getMainLooper())
    private var activeConversationId = 0
    private var lastMessageId = 0
    private var resumed = false
    private var loadingMessages = false
    private var loadingDepartments = false
    private var openChooserWhenLoaded = false
    private var pendingDepartmentId = 0
    private var pendingTopicId = 0
    private var pendingTopicName = ""
    private var pendingDepartmentName = ""
    private var pendingCreateRequestId = ""
    private var retryMessageId = ""
    private var retryMessageBody = ""

    private data class Topic(
        val id: Int,
        val name: String,
        val description: String,
        val priority: String,
    )

    private data class Department(
        val id: Int,
        val name: String,
        val description: String,
        val topics: List<Topic>,
    )

    private val departments = mutableListOf<Department>()

    private val attachmentPicker = registerForActivityResult(
        ActivityResultContracts.GetContent()
    ) { uri ->
        if (uri != null) uploadAttachment(uri)
    }

    private val poll = object : Runnable {
        override fun run() {
            if (!resumed || activeConversationId <= 0) return
            loadMessages(incremental = true)
            handler.postDelayed(this, 4500L)
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        palette = BlueVpnTheme.palette(this)
        window.statusBarColor = palette.background
        window.navigationBarColor = palette.background
        window.setSoftInputMode(WindowManager.LayoutParams.SOFT_INPUT_ADJUST_RESIZE)
        BlueVpnTheme.applySystemBars(this)
        setContentView(createScreen())
        BlueVpnSupportNotifications.schedule(this)

        if (!BlueVpnAccountManager.hasSession(this)) {
            renderSignedOut()
            return
        }

        loadDepartments()
        activeConversationId =
            savedInstanceState?.getInt("support_active_conversation", 0)
                ?: intent.getIntExtra("conversation_id", 0)
        pendingDepartmentId = savedInstanceState?.getInt("support_pending_department", 0) ?: 0
        pendingTopicId = savedInstanceState?.getInt("support_pending_topic", 0) ?: 0
        pendingDepartmentName = savedInstanceState?.getString("support_pending_department_name").orEmpty()
        pendingTopicName = savedInstanceState?.getString("support_pending_topic_name").orEmpty()
        pendingCreateRequestId = savedInstanceState?.getString("support_create_request_id").orEmpty()
        retryMessageId = savedInstanceState?.getString("support_retry_message_id").orEmpty()
        retryMessageBody = savedInstanceState?.getString("support_retry_message_body").orEmpty()
        val restoredDraft = savedInstanceState?.getString("support_draft").orEmpty()
        if (restoredDraft.isNotBlank()) messageInput.setText(restoredDraft)

        if (activeConversationId > 0) {
            loadConversations()
            loadMessages(incremental = false)
        } else {
            loadConversations()
            if (pendingDepartmentId > 0) {
                emptyState.visibility = View.GONE
                messagesScroll.visibility = View.VISIBLE
                composer.visibility = View.VISIBLE
                operatorText.text = listOf(pendingDepartmentName, pendingTopicName)
                    .filter { it.isNotBlank() }
                    .joinToString(" • ")
                    .ifBlank { "پشتیبانی" }
                operatorText.setTextColor(palette.accent)
            }
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

    override fun onSaveInstanceState(outState: Bundle) {
        outState.putInt("support_active_conversation", activeConversationId)
        outState.putInt("support_pending_department", pendingDepartmentId)
        outState.putInt("support_pending_topic", pendingTopicId)
        outState.putString("support_pending_department_name", pendingDepartmentName)
        outState.putString("support_pending_topic_name", pendingTopicName)
        outState.putString("support_create_request_id", pendingCreateRequestId)
        outState.putString("support_retry_message_id", retryMessageId)
        outState.putString("support_retry_message_body", retryMessageBody)
        outState.putString("support_draft", messageInput.text?.toString().orEmpty())
        super.onSaveInstanceState(outState)
    }


    private fun createScreen(): View {
        val frame = FrameLayout(this).apply {
            setBackgroundColor(palette.background)
        }

        root = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            layoutDirection = View.LAYOUT_DIRECTION_RTL
            setPadding(dp(18), 0, dp(18), dp(12))
        }
        frame.addView(root, FrameLayout.LayoutParams(-1, -1))

        ViewCompat.setOnApplyWindowInsetsListener(root) { view, insets ->
            val bars = insets.getInsets(WindowInsetsCompat.Type.systemBars())
            view.setPadding(dp(18), bars.top + dp(8), dp(18), bars.bottom + dp(10))
            insets
        }

        root.addView(createHeader())
        root.addView(createConversationStrip(), LinearLayout.LayoutParams(-1, dp(64)))
        root.addView(createChatSurface(), LinearLayout.LayoutParams(-1, 0, 1f))
        root.addView(createComposer(), LinearLayout.LayoutParams(-1, -2))

        return frame
    }

    private fun createHeader(): View {
        val header = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER_VERTICAL
            setPadding(0, dp(6), 0, dp(6))
        }

        val identity = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER_VERTICAL
        }

        val avatar = FrameLayout(this).apply {
            background = rounded(palette.surfaceStrong, 18, palette.stroke)
        }
        val mark = TextView(this).apply {
            text = "b"
            textSize = 24f
            gravity = Gravity.CENTER
            setTypeface(typeface, Typeface.BOLD)
            setTextColor(palette.accent)
        }
        avatar.addView(mark, FrameLayout.LayoutParams(dp(42), dp(42), Gravity.CENTER))

        val titleStack = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            gravity = Gravity.CENTER_VERTICAL or Gravity.END
            setPadding(dp(10), 0, 0, 0)
        }
        val title = TextView(this).apply {
            text = "پشتیبانی BlueVPN"
            textSize = 19f
            setTypeface(typeface, Typeface.BOLD)
            setTextColor(palette.textPrimary)
            gravity = Gravity.END
        }
        operatorText = TextView(this).apply {
            text = "پاسخ‌گویی آنلاین"
            textSize = 11.5f
            setTextColor(palette.success)
            gravity = Gravity.END
        }
        titleStack.addView(title)
        titleStack.addView(operatorText)
        identity.addView(avatar, LinearLayout.LayoutParams(dp(46), dp(46)))
        identity.addView(titleStack, LinearLayout.LayoutParams(0, -2, 1f))

        val back = MaterialButton(this).apply {
            text = "‹"
            textSize = 28f
            isAllCaps = false
            minWidth = 0
            insetTop = 0
            insetBottom = 0
            setPadding(0, 0, 0, dp(3))
            setTextColor(palette.textPrimary)
            backgroundTintList = ColorStateList.valueOf(palette.surfaceStrong)
            strokeColor = ColorStateList.valueOf(palette.stroke)
            strokeWidth = dp(1)
            cornerRadius = dp(19)
            BlueVpnUiGuard.bind(this) { finish() }
        }

        header.addView(identity, LinearLayout.LayoutParams(0, dp(56), 1f))
        header.addView(back, LinearLayout.LayoutParams(dp(46), dp(46)))
        return header
    }

    private fun createConversationStrip(): View {
        conversationStrip = HorizontalScrollView(this).apply {
            isHorizontalScrollBarEnabled = false
            overScrollMode = View.OVER_SCROLL_NEVER
            clipToPadding = false
        }
        conversationBox = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER_VERTICAL or Gravity.END
            layoutDirection = View.LAYOUT_DIRECTION_RTL
            setPadding(0, dp(7), 0, dp(7))
        }
        conversationStrip.addView(
            conversationBox,
            ViewGroup.LayoutParams(-2, -1),
        )
        return conversationStrip
    }

    private fun createChatSurface(): View {
        val container = FrameLayout(this).apply {
            background = rounded(palette.surface, 28, palette.stroke)
            clipToOutline = true
        }

        messagesScroll = ScrollView(this).apply {
            isFillViewport = true
            overScrollMode = View.OVER_SCROLL_IF_CONTENT_SCROLLS
            isVerticalScrollBarEnabled = false
            setPadding(dp(10), dp(10), dp(10), dp(10))
        }
        messagesBox = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            gravity = Gravity.BOTTOM
            setPadding(dp(4), dp(4), dp(4), dp(4))
        }
        messagesScroll.addView(
            messagesBox,
            ViewGroup.LayoutParams(-1, -2),
        )
        container.addView(
            messagesScroll,
            FrameLayout.LayoutParams(-1, -1),
        )

        emptyState = createEmptyState()
        container.addView(
            emptyState,
            FrameLayout.LayoutParams(-1, -1),
        )

        departmentSheet = createDepartmentSheet()
        departmentSheet.visibility = View.GONE
        container.addView(
            departmentSheet,
            FrameLayout.LayoutParams(-1, -1).apply {
                gravity = Gravity.CENTER
                setMargins(dp(10), dp(10), dp(10), dp(10))
            },
        )

        return container
    }

    private fun createEmptyState(): LinearLayout {
        return LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            gravity = Gravity.CENTER
            setPadding(dp(28), dp(24), dp(28), dp(24))

            val iconWrap = FrameLayout(this@BlueVpnSupportActivity).apply {
                background = rounded(withAlpha(palette.accent, 0.12f), 30)
            }
            val icon = TextView(this@BlueVpnSupportActivity).apply {
                text = "💬"
                textSize = 34f
                gravity = Gravity.CENTER
            }
            iconWrap.addView(icon, FrameLayout.LayoutParams(dp(66), dp(66), Gravity.CENTER))
            addView(iconWrap, LinearLayout.LayoutParams(dp(72), dp(72)).apply {
                bottomMargin = dp(16)
            })

            addView(TextView(this@BlueVpnSupportActivity).apply {
                text = "گفتگو با تیم BlueVPN"
                textSize = 21f
                setTypeface(typeface, Typeface.BOLD)
                setTextColor(palette.textPrimary)
                gravity = Gravity.CENTER
            })

            statusText = TextView(this@BlueVpnSupportActivity).apply {
                text = "موضوع را انتخاب کنید و پیام‌تان را بنویسید.\nپاسخ پشتیبانی همین‌جا نمایش داده می‌شود."
                textSize = 13f
                setLineSpacing(0f, 1.25f)
                setTextColor(palette.textSecondary)
                gravity = Gravity.CENTER
                setPadding(0, dp(8), 0, dp(18))
            }
            addView(statusText)

            val start = MaterialButton(this@BlueVpnSupportActivity).apply {
                text = "شروع گفتگو"
                textSize = 14f
                isAllCaps = false
                setTextColor(Color.WHITE)
                backgroundTintList = ColorStateList.valueOf(palette.accent)
                cornerRadius = dp(18)
                BlueVpnUiGuard.bind(this) { showDepartmentChooser() }
            }
            addView(start, LinearLayout.LayoutParams(dp(170), dp(52)))
        }
    }

    private fun createDepartmentSheet(): MaterialCardView {
        val card = MaterialCardView(this).apply {
            radius = dp(26).toFloat()
            cardElevation = dp(4).toFloat()
            setCardBackgroundColor(palette.surfaceStrong)
            strokeColor = palette.stroke
            strokeWidth = dp(1)
        }
        val body = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(dp(18), dp(20), dp(18), dp(18))
        }

        departmentTitle = TextView(this).apply {
            text = "انتخاب بخش"
            textSize = 20f
            setTypeface(typeface, Typeface.BOLD)
            setTextColor(palette.textPrimary)
            gravity = Gravity.END
        }
        departmentSubtitle = TextView(this).apply {
            text = "ابتدا بخش مربوط به درخواست خود را انتخاب کنید."
            textSize = 12f
            setTextColor(palette.textSecondary)
            gravity = Gravity.END
            setPadding(0, dp(5), 0, dp(12))
        }
        departmentList = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
        }
        val cancel = MaterialButton(this).apply {
            text = "انصراف"
            textSize = 13f
            isAllCaps = false
            setTextColor(palette.textSecondary)
            backgroundTintList = ColorStateList.valueOf(palette.surface)
            cornerRadius = dp(17)
            BlueVpnUiGuard.bind(this) {
                departmentSheet.visibility = View.GONE
                if (activeConversationId <= 0 && pendingTopicId <= 0) {
                    pendingDepartmentId = 0
                    pendingDepartmentName = ""
                }
                updateEmptyVisibility()
            }
        }

        body.addView(departmentTitle)
        body.addView(departmentSubtitle)
        val chooserScroll = ScrollView(this).apply {
            isFillViewport = true
            isVerticalScrollBarEnabled = false
            addView(departmentList, FrameLayout.LayoutParams(-1, -2))
        }
        body.addView(chooserScroll, LinearLayout.LayoutParams(-1, 0, 1f))
        body.addView(cancel, LinearLayout.LayoutParams(-1, dp(48)).apply {
            topMargin = dp(12)
        })
        card.addView(body)
        return card
    }

    private fun createComposer(): View {
        composer = MaterialCardView(this).apply {
            radius = dp(25).toFloat()
            cardElevation = 0f
            setCardBackgroundColor(palette.surfaceStrong)
            strokeColor = palette.stroke
            strokeWidth = dp(1)
            setContentPadding(dp(7), dp(6), dp(7), dp(6))
        }

        val row = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER_VERTICAL
            layoutDirection = View.LAYOUT_DIRECTION_RTL
        }

        attachButton = MaterialButton(this).apply {
            text = "+"
            textSize = 24f
            isAllCaps = false
            minWidth = 0
            insetTop = 0
            insetBottom = 0
            setPadding(0, 0, 0, dp(2))
            setTextColor(palette.textPrimary)
            backgroundTintList = ColorStateList.valueOf(palette.surfaceSoft)
            cornerRadius = dp(20)
            BlueVpnUiGuard.bind(this) {
                if (activeConversationId <= 0) {
                    if (pendingDepartmentId > 0) {
                        Toast.makeText(
                            this@BlueVpnSupportActivity,
                            "ابتدا پیام اول را ارسال کنید تا گفتگو ساخته شود؛ سپس فایل را پیوست کنید",
                            Toast.LENGTH_SHORT,
                        ).show()
                    } else {
                        showDepartmentChooser()
                    }
                } else {
                    attachmentPicker.launch("*/*")
                }
            }
        }

        messageInput = EditText(this).apply {
            hint = "پیام خود را بنویسید…"
            textSize = 14f
            setTextColor(palette.textPrimary)
            setHintTextColor(palette.textMuted)
            gravity = Gravity.CENTER_VERTICAL or Gravity.END
            setPadding(dp(12), dp(8), dp(12), dp(8))
            background = rounded(palette.surface, 20)
            isFocusableInTouchMode = true
            isClickable = true
            setSingleLine(false)
            maxLines = 4
            inputType = InputType.TYPE_CLASS_TEXT or
                InputType.TYPE_TEXT_FLAG_CAP_SENTENCES or
                InputType.TYPE_TEXT_FLAG_MULTI_LINE
            imeOptions = EditorInfo.IME_ACTION_SEND
            setOnClickListener { requestFocus() }
            setOnEditorActionListener { _, actionId, _ ->
                if (actionId == EditorInfo.IME_ACTION_SEND) {
                    sendMessage()
                    true
                } else false
            }
        }

        sendButton = MaterialButton(this).apply {
            text = "➤"
            textSize = 21f
            isAllCaps = false
            minWidth = 0
            insetTop = 0
            insetBottom = 0
            setPadding(dp(2), 0, 0, 0)
            setTextColor(Color.WHITE)
            backgroundTintList = ColorStateList.valueOf(palette.accent)
            cornerRadius = dp(22)
            BlueVpnUiGuard.bind(this) { sendMessage() }
        }

        row.addView(sendButton, LinearLayout.LayoutParams(dp(46), dp(46)))
        row.addView(messageInput, LinearLayout.LayoutParams(0, -2, 1f).apply {
            marginStart = dp(7)
            marginEnd = dp(7)
        })
        row.addView(attachButton, LinearLayout.LayoutParams(dp(46), dp(46)))
        composer.addView(row)
        return composer
    }

    private fun renderSignedOut() {
        conversationBox.removeAllViews()
        activeConversationId = 0
        pendingDepartmentId = 0
        pendingTopicId = 0
        pendingTopicName = ""
        pendingDepartmentName = ""
        pendingCreateRequestId = ""
        retryMessageId = ""
        retryMessageBody = ""
        messagesBox.removeAllViews()
        emptyState.visibility = View.VISIBLE
        departmentSheet.visibility = View.GONE
        composer.visibility = View.GONE
        statusText.text = "برای گفتگو با پشتیبانی ابتدا وارد حساب BlueVPN شوید."
        operatorText.text = "نیاز به ورود"
        operatorText.setTextColor(palette.warning)
    }

    private fun loadDepartments() {
        if (loadingDepartments) {
            openChooserWhenLoaded = true
            return
        }
        loadingDepartments = true
        lifecycleScope.launch(Dispatchers.IO) {
            val result = BlueVpnAccountManager.supportRequest(
                this@BlueVpnSupportActivity,
                "GET",
                "/api/v1/support/departments",
            )
            withContext(Dispatchers.Main) {
                loadingDepartments = false
                result.onSuccess { json ->
                    val rows = json.optJSONArray("departments") ?: JSONArray()
                    departments.clear()
                    for (i in 0 until rows.length()) {
                        val row = rows.optJSONObject(i) ?: continue
                        val topicsJson = row.optJSONArray("topics") ?: JSONArray()
                        val topics = mutableListOf<Topic>()
                        for (j in 0 until topicsJson.length()) {
                            val topic = topicsJson.optJSONObject(j) ?: continue
                            val id = topic.optInt("id")
                            if (id <= 0) continue
                            topics += Topic(
                                id = id,
                                name = topic.optString("name", "موضوع"),
                                description = topic.optString("description"),
                                priority = topic.optString("priority", "normal"),
                            )
                        }
                        val id = row.optInt("id")
                        if (id <= 0) continue
                        departments += Department(
                            id = id,
                            name = row.optString("name", "پشتیبانی"),
                            description = row.optString("description"),
                            topics = topics,
                        )
                    }
                    rebuildDepartmentList()
                    if (openChooserWhenLoaded) {
                        openChooserWhenLoaded = false
                        showDepartmentChooser(resetSelection = true)
                    }
                }.onFailure {
                    statusText.text = "دریافت بخش‌های پشتیبانی ناموفق بود. برای تلاش مجدد روی «گفتگوی جدید» بزنید."
                    if (openChooserWhenLoaded) {
                        Toast.makeText(
                            this@BlueVpnSupportActivity,
                            "دریافت موضوع‌های پشتیبانی ناموفق بود",
                            Toast.LENGTH_SHORT,
                        ).show()
                    }
                    openChooserWhenLoaded = false
                }
            }
        }
    }

    private fun rebuildDepartmentList() {
        departmentTitle.text = "انتخاب بخش"
        departmentSubtitle.text = "ابتدا بخش مربوط به درخواست خود را انتخاب کنید."
        departmentList.removeAllViews()

        departments.forEach { department ->
            val card = supportChoiceCard(
                title = department.name,
                subtitle = department.description.ifBlank { "پشتیبانی BlueVPN" },
                badge = if (department.topics.isNotEmpty()) "${department.topics.size} موضوع" else "",
            ) {
                if (department.topics.isEmpty()) {
                    selectPendingTopic(
                        department = department,
                        topic = null,
                    )
                } else {
                    showTopicChooser(department)
                }
            }
            departmentList.addView(card, LinearLayout.LayoutParams(-1, -2).apply {
                bottomMargin = dp(8)
            })
        }
    }

    private fun supportChoiceCard(
        title: String,
        subtitle: String,
        badge: String = "",
        onClick: () -> Unit,
    ): MaterialCardView {
        val card = MaterialCardView(this).apply {
            radius = dp(18).toFloat()
            cardElevation = 0f
            setCardBackgroundColor(palette.surface)
            strokeColor = palette.stroke
            strokeWidth = dp(1)
            isClickable = true
            isFocusable = true
            setOnClickListener { onClick() }
        }
        val row = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER_VERTICAL
            layoutDirection = View.LAYOUT_DIRECTION_RTL
            setPadding(dp(14), dp(11), dp(14), dp(11))
        }
        val textStack = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            gravity = Gravity.END
        }
        textStack.addView(TextView(this).apply {
            text = title
            textSize = 14f
            setTypeface(typeface, Typeface.BOLD)
            setTextColor(palette.textPrimary)
            gravity = Gravity.END
        })
        textStack.addView(TextView(this).apply {
            text = subtitle
            textSize = 11f
            setTextColor(palette.textMuted)
            gravity = Gravity.END
        })
        val badgeView = TextView(this).apply {
            text = badge.ifBlank { "‹" }
            textSize = if (badge.isBlank()) 24f else 10.5f
            setTextColor(palette.accent)
            gravity = Gravity.CENTER
            setPadding(dp(8), 0, dp(8), 0)
        }
        row.addView(textStack, LinearLayout.LayoutParams(0, -2, 1f))
        row.addView(badgeView, LinearLayout.LayoutParams(-2, dp(42)))
        card.addView(row)
        return card
    }

    private fun showTopicChooser(department: Department) {
        pendingDepartmentId = department.id
        pendingDepartmentName = department.name
        pendingTopicId = 0
        pendingTopicName = ""

        departmentTitle.text = "انتخاب موضوع"
        departmentSubtitle.text = "بخش ${department.name} • موضوع دقیق درخواست را انتخاب کنید."
        departmentList.removeAllViews()

        val back = MaterialButton(this).apply {
            text = "بازگشت به بخش‌ها"
            textSize = 12f
            isAllCaps = false
            setTextColor(palette.textSecondary)
            backgroundTintList = ColorStateList.valueOf(palette.surfaceSoft)
            cornerRadius = dp(16)
            BlueVpnUiGuard.bind(this) {
                pendingDepartmentId = 0
                pendingDepartmentName = ""
                rebuildDepartmentList()
            }
        }
        departmentList.addView(back, LinearLayout.LayoutParams(-1, dp(46)).apply {
            bottomMargin = dp(10)
        })

        department.topics.forEach { topic ->
            val priorityLabel = when (topic.priority) {
                "urgent" -> "فوری"
                "high" -> "مهم"
                "low" -> "عادی"
                else -> ""
            }
            val card = supportChoiceCard(
                title = topic.name,
                subtitle = topic.description.ifBlank { department.name },
                badge = priorityLabel,
            ) {
                selectPendingTopic(department, topic)
            }
            departmentList.addView(card, LinearLayout.LayoutParams(-1, -2).apply {
                bottomMargin = dp(8)
            })
        }
    }

    private fun selectPendingTopic(
        department: Department,
        topic: Topic?,
    ) {
        pendingDepartmentId = department.id
        pendingDepartmentName = department.name
        pendingTopicId = topic?.id ?: 0
        pendingTopicName = topic?.name.orEmpty()

        departmentSheet.visibility = View.GONE
        messagesScroll.visibility = View.VISIBLE
        emptyState.visibility = View.GONE
        composer.visibility = View.VISIBLE
        operatorText.text = if (pendingTopicName.isNotBlank()) {
            "${department.name} • ${pendingTopicName}"
        } else {
            department.name
        }
        operatorText.setTextColor(palette.accent)
        statusText.text = if (pendingTopicName.isNotBlank()) {
            "موضوع «${pendingTopicName}» انتخاب شد. پیام خود را بنویسید و ارسال کنید."
        } else {
            "بخش «${department.name}» انتخاب شد. پیام خود را بنویسید و ارسال کنید."
        }
        Toast.makeText(
            this,
            if (pendingTopicName.isNotBlank()) "موضوع «${pendingTopicName}» انتخاب شد" else "بخش «${department.name}» انتخاب شد",
            Toast.LENGTH_SHORT,
        ).show()
        messageInput.hint = "پیام خود را بنویسید…"
        messageInput.requestFocus()
    }

    private fun showDepartmentChooser(resetSelection: Boolean = true) {
        if (!BlueVpnAccountManager.hasSession(this)) {
            renderSignedOut()
            return
        }
        if (resetSelection) {
            pendingDepartmentId = 0
            pendingDepartmentName = ""
            pendingTopicId = 0
            pendingTopicName = ""
            pendingCreateRequestId = ""
        }
        if (departments.isEmpty()) {
            openChooserWhenLoaded = true
            statusText.text = "در حال دریافت بخش‌ها و موضوع‌های پشتیبانی…"
            loadDepartments()
            return
        }
        rebuildDepartmentList()
        emptyState.visibility = View.GONE
        messagesScroll.visibility = View.GONE
        departmentSheet.visibility = View.VISIBLE
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
                    addNewConversationChip()
                    val rows = json.optJSONArray("conversations") ?: JSONArray()
                    for (i in 0 until rows.length()) {
                        rows.optJSONObject(i)?.let(::addConversationChip)
                    }
                    if (selectNewest && rows.length() > 0) {
                        val id = rows.optJSONObject(0)?.optInt("id") ?: 0
                        if (id > 0) openConversation(id)
                    } else {
                        updateEmptyVisibility()
                    }
                }
            }
        }
    }

    private fun addNewConversationChip() {
        val button = MaterialButton(this).apply {
            text = "+ گفتگوی جدید"
            textSize = 11f
            isAllCaps = false
            setTextColor(palette.accent)
            backgroundTintList = ColorStateList.valueOf(palette.surfaceStrong)
            strokeColor = ColorStateList.valueOf(palette.stroke)
            strokeWidth = dp(1)
            cornerRadius = dp(16)
            minWidth = 0
            insetTop = 0
            insetBottom = 0
            BlueVpnUiGuard.bind(this) { showDepartmentChooser() }
        }
        conversationBox.addView(button, LinearLayout.LayoutParams(-2, dp(44)).apply {
            marginEnd = dp(7)
        })
    }

    private fun addConversationChip(row: JSONObject) {
        val id = row.optInt("id")
        val department = row.optJSONObject("department")?.optString("name").orEmpty()
        val topic = row.optJSONObject("topic")?.optString("name").orEmpty()
        val unread = row.optInt("unread")
        val active = id == activeConversationId

        val button = MaterialButton(this).apply {
            text = buildString {
                if (unread > 0) append("● ")
                append(
                    if (topic.isNotBlank()) topic
                    else department.ifBlank { "پشتیبانی" }
                )
            }
            textSize = 11f
            isAllCaps = false
            minWidth = 0
            insetTop = 0
            insetBottom = 0
            cornerRadius = dp(16)
            backgroundTintList = ColorStateList.valueOf(
                if (active) palette.accent else palette.surfaceStrong
            )
            setTextColor(if (active) Color.WHITE else palette.textPrimary)
            strokeColor = ColorStateList.valueOf(
                if (active) palette.accent else palette.stroke
            )
            strokeWidth = dp(1)
            BlueVpnUiGuard.bind(this) { openConversation(id) }
        }
        conversationBox.addView(button, LinearLayout.LayoutParams(-2, dp(44)).apply {
            marginEnd = dp(7)
        })
    }

    private fun beginNewConversation(
        departmentId: Int,
        topicId: Int,
        firstMessage: String,
    ) {
        val value = firstMessage.trim()
        if (departmentId <= 0 || value.isBlank()) return

        if (pendingCreateRequestId.isBlank()) {
            pendingCreateRequestId = UUID.randomUUID().toString().replace("-", "")
        }
        val requestId = pendingCreateRequestId
        setComposerEnabled(false)

        lifecycleScope.launch(Dispatchers.IO) {
            val body = JSONObject()
                .put("department_id", departmentId)
                .put("topic_id", topicId)
                .put("subject", pendingTopicName.ifBlank { value.take(70) })
                .put("message", value)
                .put("client_request_id", requestId)

            val result = BlueVpnAccountManager.supportRequest(
                this@BlueVpnSupportActivity,
                "POST",
                "/api/v1/support/conversations",
                body,
            )
            withContext(Dispatchers.Main) {
                setComposerEnabled(true)
                result.onSuccess { json ->
                    val id = json.optJSONObject("conversation")?.optInt("id") ?: 0
                    if (id > 0) {
                        messageInput.setText("")
                        activeConversationId = id
                        lastMessageId = 0
                        pendingDepartmentId = 0
                        pendingTopicId = 0
                        pendingTopicName = ""
                        pendingDepartmentName = ""
                        pendingCreateRequestId = ""
                        departmentSheet.visibility = View.GONE
                        messagesScroll.visibility = View.VISIBLE
                        emptyState.visibility = View.GONE
                        loadConversations()
                        loadMessages(incremental = false)
                    } else {
                        Toast.makeText(
                            this@BlueVpnSupportActivity,
                            "پاسخ ایجاد گفتگو کامل نبود؛ دوباره تلاش کنید",
                            Toast.LENGTH_SHORT,
                        ).show()
                    }
                }.onFailure {
                    // Keep request id so a retry cannot duplicate a conversation
                    // if the server committed before the response was lost.
                    Toast.makeText(
                        this@BlueVpnSupportActivity,
                        "ایجاد گفتگو ناموفق بود؛ پیام شما حفظ شد",
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
        pendingDepartmentId = 0
        pendingTopicId = 0
        pendingTopicName = ""
        pendingDepartmentName = ""
        pendingCreateRequestId = ""
        messagesBox.removeAllViews()
        emptyState.visibility = View.GONE
        departmentSheet.visibility = View.GONE
        messagesScroll.visibility = View.VISIBLE
        composer.visibility = View.VISIBLE
        loadConversations()
        loadMessages(incremental = false)
        handler.removeCallbacks(poll)
        handler.postDelayed(poll, 4500L)
    }

    private fun loadMessages(incremental: Boolean) {
        val cid = activeConversationId
        if (cid <= 0 || loadingMessages) return

        loadingMessages = true
        val after = if (incremental) lastMessageId else 0

        lifecycleScope.launch(Dispatchers.IO) {
            val result = BlueVpnAccountManager.supportRequest(
                this@BlueVpnSupportActivity,
                "GET",
                "/api/v1/support/conversations/$cid/messages?after_id=$after",
            )
            withContext(Dispatchers.Main) {
                loadingMessages = false
                result.onSuccess { json ->
                    renderConversationHeader(json.optJSONObject("conversation"))

                    val rows = json.optJSONArray("messages") ?: JSONArray()
                    if (!incremental) messagesBox.removeAllViews()
                    var added = 0
                    for (i in 0 until rows.length()) {
                        val row = rows.optJSONObject(i) ?: continue
                        val id = row.optInt("id")
                        if (incremental && id <= lastMessageId) continue
                        lastMessageId = maxOf(lastMessageId, id)
                        addMessageBubble(row)
                        added++
                    }
                    emptyState.visibility =
                        if (messagesBox.childCount == 0) View.VISIBLE else View.GONE
                    if (added > 0 || !incremental) scrollToBottom()
                    loadConversations()
                }.onFailure {
                    if (!incremental) {
                        operatorText.text = "مشکل در دریافت پیام‌ها"
                        operatorText.setTextColor(palette.warning)
                    }
                }
            }
        }
    }

    private fun renderConversationHeader(conv: JSONObject?) {
        if (conv == null) return
        val op = conv.optJSONObject("operator")
        val department = conv.optJSONObject("department")?.optString("name").orEmpty()
        val topic = conv.optJSONObject("topic")?.optString("name").orEmpty()
        val state = conv.optString("status")

        if (op != null) {
            operatorText.text = buildString {
                append(op.optString("display_name", "پشتیبانی"))
                if (topic.isNotBlank()) {
                    append(" • ")
                    append(topic)
                }
                append(" • ")
                append(if (op.optBoolean("online", false)) "آنلاین" else "پاسخ‌گو")
            }
            operatorText.setTextColor(
                if (op.optBoolean("online", false)) palette.success else palette.textMuted
            )
        } else {
            operatorText.text = buildString {
                append(department.ifBlank { "پشتیبانی" })
                if (topic.isNotBlank()) {
                    append(" • ")
                    append(topic)
                }
                append(" • ")
                append(
                    when (state) {
                        "waiting" -> "در صف پاسخ"
                        "pending_customer" -> "منتظر شما"
                        "resolved" -> "حل‌شده"
                        "closed" -> "بسته"
                        else -> "فعال"
                    }
                )
            }
            operatorText.setTextColor(
                if (state == "closed") palette.textMuted else palette.accent
            )
        }

        val closed = state == "closed"
        setComposerEnabled(!closed)
        if (closed) {
            messageInput.hint = "این گفتگو بسته شده است"
        } else {
            messageInput.hint = "پیام خود را بنویسید…"
        }
    }

    private fun addMessageBubble(row: JSONObject) {
        val customer = row.optString("sender") == "customer"
        val body = row.optString("body")
        val createdAt = row.optString("created_at")
        val seen = row.optBoolean("seen", false)
        val attachments = row.optJSONArray("attachments") ?: JSONArray()

        val outer = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            gravity = if (customer) Gravity.END else Gravity.START
            setPadding(
                if (customer) dp(44) else 0,
                dp(3),
                if (customer) 0 else dp(44),
                dp(3),
            )
        }

        if (!customer) {
            outer.addView(TextView(this).apply {
                text = "BlueVPN"
                textSize = 10.5f
                setTypeface(typeface, Typeface.BOLD)
                setTextColor(palette.accent)
                gravity = Gravity.START
                setPadding(dp(10), 0, 0, dp(3))
            })
        }

        val bubble = MaterialCardView(this).apply {
            radius = dp(19).toFloat()
            cardElevation = 0f
            setCardBackgroundColor(
                if (customer) palette.accent else palette.surfaceStrong
            )
            strokeColor = if (customer) palette.accent else palette.stroke
            strokeWidth = if (customer) 0 else dp(1)
        }

        val content = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(dp(13), dp(10), dp(13), dp(8))
        }
        content.addView(TextView(this).apply {
            text = body
            textSize = 14.5f
            setLineSpacing(0f, 1.16f)
            setTextColor(if (customer) Color.WHITE else palette.textPrimary)
            gravity = Gravity.END
        })

        for (i in 0 until attachments.length()) {
            val item = attachments.optJSONObject(i) ?: continue
            val fileName = item.optString("name", "فایل پیوست")
            val mime = item.optString("mime")
            val size = item.optLong("size", 0L)
            content.addView(TextView(this).apply {
                text = "📎  $fileName\n${humanSize(size)} • ${mime.substringAfterLast('/')}"
                textSize = 11.5f
                setTextColor(
                    if (customer) withAlpha(Color.WHITE, 0.9f) else palette.textSecondary
                )
                background = rounded(
                    if (customer) withAlpha(Color.WHITE, 0.12f) else palette.surfaceSoft,
                    13,
                )
                setPadding(dp(10), dp(8), dp(10), dp(8))
                gravity = Gravity.END
            }, LinearLayout.LayoutParams(-1, -2).apply {
                topMargin = dp(8)
            })
        }

        val meta = TextView(this).apply {
            text = buildString {
                append(formatMessageTime(createdAt))
                if (customer) {
                    append(if (seen) "  ✓✓" else "  ✓")
                }
            }
            textSize = 9.5f
            setTextColor(
                if (customer) withAlpha(Color.WHITE, 0.72f) else palette.textMuted
            )
            gravity = if (customer) Gravity.START else Gravity.END
            setPadding(0, dp(5), 0, 0)
        }
        content.addView(meta)
        bubble.addView(content)

        outer.addView(
            bubble,
            LinearLayout.LayoutParams(-2, -2).apply {
                gravity = if (customer) Gravity.END else Gravity.START
            },
        )
        messagesBox.addView(outer, LinearLayout.LayoutParams(-1, -2))
    }

    private fun uploadAttachment(uri: Uri) {
        val cid = activeConversationId
        if (cid <= 0) {
            if (pendingDepartmentId > 0) {
                Toast.makeText(
                    this,
                    "ابتدا پیام اول را ارسال کنید تا گفتگو ساخته شود",
                    Toast.LENGTH_SHORT,
                ).show()
            } else {
                showDepartmentChooser()
            }
            return
        }

        setComposerEnabled(false)
        lifecycleScope.launch(Dispatchers.IO) {
            val resolver = contentResolver
            val mime = resolver.getType(uri)?.lowercase(Locale.ROOT).orEmpty()
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
                    setComposerEnabled(true)
                    Toast.makeText(
                        this@BlueVpnSupportActivity,
                        "فرمت فایل پشتیبانی نمی‌شود",
                        Toast.LENGTH_SHORT,
                    ).show()
                }
                return@launch
            }

            val bytes = runCatching {
                resolver.openInputStream(uri)?.use { input ->
                    val out = ByteArrayOutputStream()
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
                    setComposerEnabled(true)
                    Toast.makeText(
                        this@BlueVpnSupportActivity,
                        if (error.message == "ATTACHMENT_TOO_LARGE") {
                            "حداکثر حجم فایل ۴ مگابایت است"
                        } else {
                            "خواندن فایل ناموفق بود"
                        },
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

            val payload = JSONObject()
                .put("name", name)
                .put("mime", mime)
                .put("data_base64", Base64.encodeToString(bytes, Base64.NO_WRAP))

            val result = BlueVpnAccountManager.supportRequest(
                this@BlueVpnSupportActivity,
                "POST",
                "/api/v1/support/conversations/$cid/attachments",
                payload,
            )
            withContext(Dispatchers.Main) {
                setComposerEnabled(true)
                result.onSuccess {
                    Toast.makeText(
                        this@BlueVpnSupportActivity,
                        "فایل ارسال شد",
                        Toast.LENGTH_SHORT,
                    ).show()
                    loadMessages(incremental = true)
                }.onFailure {
                    Toast.makeText(
                        this@BlueVpnSupportActivity,
                        "ارسال فایل ناموفق بود",
                        Toast.LENGTH_SHORT,
                    ).show()
                }
            }
        }
    }

    private fun sendMessage() {
        val value = messageInput.text?.toString()?.trim().orEmpty()
        if (value.isBlank()) return

        if (activeConversationId <= 0) {
            if (pendingDepartmentId <= 0) {
                showDepartmentChooser(resetSelection = true)
                return
            }
            beginNewConversation(
                departmentId = pendingDepartmentId,
                topicId = pendingTopicId,
                firstMessage = value,
            )
            return
        }

        val cid = activeConversationId
        val clientMessageId =
            if (retryMessageId.isNotBlank() && retryMessageBody == value) {
                retryMessageId
            } else {
                UUID.randomUUID().toString().replace("-", "")
            }
        retryMessageId = clientMessageId
        retryMessageBody = value

        val optimistic = JSONObject()
            .put("id", lastMessageId + 1)
            .put("sender", "customer")
            .put("body", value)
            .put("created_at", "")
            .put("seen", false)
            .put("attachments", JSONArray())

        messageInput.setText("")
        addMessageBubble(optimistic)
        scrollToBottom()
        setComposerEnabled(false)

        lifecycleScope.launch(Dispatchers.IO) {
            val result = BlueVpnAccountManager.supportRequest(
                this@BlueVpnSupportActivity,
                "POST",
                "/api/v1/support/conversations/$cid/messages",
                JSONObject()
                    .put("message", value)
                    .put("client_message_id", clientMessageId),
            )
            withContext(Dispatchers.Main) {
                setComposerEnabled(true)
                result.onSuccess {
                    retryMessageId = ""
                    retryMessageBody = ""
                    loadMessages(incremental = false)
                }.onFailure {
                    messageInput.setText(value)
                    loadMessages(incremental = false)
                    Toast.makeText(
                        this@BlueVpnSupportActivity,
                        "ارسال پیام ناموفق بود؛ برای Retry دوباره ارسال کنید",
                        Toast.LENGTH_SHORT,
                    ).show()
                }
            }
        }
    }

    private fun setComposerEnabled(enabled: Boolean) {
        messageInput.isEnabled = enabled
        attachButton.isEnabled = enabled
        sendButton.isEnabled = enabled
        composer.alpha = if (enabled) 1f else 0.72f
    }

    private fun updateEmptyVisibility() {
        if (departmentSheet.visibility == View.VISIBLE) return
        if (activeConversationId <= 0) {
            messagesScroll.visibility = View.VISIBLE
            if (pendingDepartmentId > 0) {
                emptyState.visibility = View.GONE
                operatorText.text = listOf(pendingDepartmentName, pendingTopicName)
                    .filter { it.isNotBlank() }
                    .joinToString(" • ")
                    .ifBlank { "پشتیبانی" }
                operatorText.setTextColor(palette.accent)
                composer.visibility = View.VISIBLE
            } else {
                emptyState.visibility = View.VISIBLE
                operatorText.text = "پاسخ‌گویی آنلاین"
                operatorText.setTextColor(palette.success)
            }
        } else {
            emptyState.visibility =
                if (messagesBox.childCount == 0) View.VISIBLE else View.GONE
        }
    }

    private fun scrollToBottom() {
        messagesScroll.post {
            messagesScroll.fullScroll(View.FOCUS_DOWN)
        }
    }

    private fun formatMessageTime(raw: String): String {
        if (raw.isBlank()) return "اکنون"
        return BlueVpnPersianDate.formatIso(raw, includeTime = true)
            ?.substringAfter("ساعت ")
            ?.trim()
            ?.takeIf { it.isNotBlank() }
            ?: raw.takeLast(5)
    }

    private fun humanSize(bytes: Long): String = when {
        bytes <= 0L -> ""
        bytes < 1024L -> "$bytes B"
        bytes < 1024L * 1024L -> "${bytes / 1024L} KB"
        else -> String.format(Locale.US, "%.1f MB", bytes / (1024.0 * 1024.0))
    }

    private fun rounded(
        fill: Int,
        radiusDp: Int,
        stroke: Int? = null,
    ): GradientDrawable = GradientDrawable().apply {
        shape = GradientDrawable.RECTANGLE
        cornerRadius = dp(radiusDp).toFloat()
        setColor(fill)
        if (stroke != null) setStroke(dp(1), stroke)
    }

    private fun withAlpha(color: Int, alpha: Float): Int =
        Color.argb(
            (255 * alpha.coerceIn(0f, 1f)).toInt(),
            Color.red(color),
            Color.green(color),
            Color.blue(color),
        )

    private fun dp(value: Int): Int =
        (value * resources.displayMetrics.density).toInt()
}
