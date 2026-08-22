using System.Text.Json.Serialization;

namespace BlueVPN.Windows.Models;

public sealed class OrderCreateResponse
{
    [JsonPropertyName("success")] public bool Success { get; set; }
    [JsonPropertyName("reused")] public bool Reused { get; set; }
    [JsonPropertyName("order")] public OrderInfo Order { get; set; } = new();
    [JsonPropertyName("poll_interval_seconds")] public int PollIntervalSeconds { get; set; } = 5;
    [JsonPropertyName("poll_timeout_seconds")] public int PollTimeoutSeconds { get; set; } = 45;
}

public sealed class OrderStatusResponse
{
    [JsonPropertyName("success")] public bool Success { get; set; }
    [JsonPropertyName("confirmed")] public bool Confirmed { get; set; }
    [JsonPropertyName("pending")] public bool Pending { get; set; }
    [JsonPropertyName("retry_after_seconds")] public int RetryAfterSeconds { get; set; }
    [JsonPropertyName("order")] public OrderInfo Order { get; set; } = new();
}

public sealed class CheckoutResponse
{
    [JsonPropertyName("success")] public bool Success { get; set; }
    [JsonPropertyName("close_grace_seconds")] public int CloseGraceSeconds { get; set; }
    [JsonPropertyName("order")] public OrderInfo Order { get; set; } = new();
}

public sealed class OrderInfo
{
    [JsonPropertyName("id")] public string Id { get; set; } = "";
    [JsonPropertyName("order_code")] public string OrderCode { get; set; } = "";
    [JsonPropertyName("status")] public string Status { get; set; } = "";
    [JsonPropertyName("payment_url")] public string PaymentUrl { get; set; } = "";
    [JsonPropertyName("amount_toman")] public int AmountToman { get; set; }
    [JsonPropertyName("expires_at_fa")] public string ExpiresAtFa { get; set; } = "";
    [JsonPropertyName("activation_error")] public string ActivationError { get; set; } = "";
    [JsonPropertyName("account")] public Account? Account { get; set; }
}

public sealed class SupportDepartmentsResponse
{
    [JsonPropertyName("departments")] public List<SupportDepartment> Departments { get; set; } = [];
}

public sealed class SupportDepartment
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("name")] public string Name { get; set; } = "";
    [JsonPropertyName("slug")] public string Slug { get; set; } = "";
    [JsonPropertyName("description")] public string Description { get; set; } = "";
    [JsonPropertyName("topics")] public List<SupportTopic> Topics { get; set; } = [];
}

public sealed class SupportTopic
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("department_id")] public int DepartmentId { get; set; }
    [JsonPropertyName("name")] public string Name { get; set; } = "";
    [JsonPropertyName("description")] public string Description { get; set; } = "";
    [JsonPropertyName("priority")] public string Priority { get; set; } = "normal";
}

public sealed class SupportConversationsResponse
{
    [JsonPropertyName("conversations")] public List<SupportConversation> Conversations { get; set; } = [];
}

public sealed class SupportConversationResponse
{
    [JsonPropertyName("conversation")] public SupportConversation Conversation { get; set; } = new();
    [JsonPropertyName("duplicate")] public bool Duplicate { get; set; }
}

public sealed class SupportConversation
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("public_id")] public string PublicId { get; set; } = "";
    [JsonPropertyName("subject")] public string Subject { get; set; } = "";
    [JsonPropertyName("status")] public string Status { get; set; } = "";
    [JsonPropertyName("priority")] public string Priority { get; set; } = "normal";
    [JsonPropertyName("department")] public SupportDepartment? Department { get; set; }
    [JsonPropertyName("operator")] public SupportOperator? Operator { get; set; }
    [JsonPropertyName("unread")] public int Unread { get; set; }
    [JsonPropertyName("last_message_at")] public string LastMessageAt { get; set; } = "";

    [JsonIgnore] public string DisplayTitle => $"{(Unread > 0 ? "● " : "")}{(string.IsNullOrWhiteSpace(Subject) ? "گفتگوی پشتیبانی" : Subject)} • {StatusLabel}";
    [JsonIgnore] public string StatusLabel => Status switch
    {
        "open" => "باز",
        "waiting" => "در انتظار پاسخ",
        "pending_customer" => "منتظر پاسخ شما",
        "closed" => "بسته",
        _ => Status
    };
}

public sealed class SupportOperator
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("display_name")] public string DisplayName { get; set; } = "";
    [JsonPropertyName("online")] public bool Online { get; set; }
}

public sealed class SupportMessagesResponse
{
    [JsonPropertyName("conversation")] public SupportConversation Conversation { get; set; } = new();
    [JsonPropertyName("messages")] public List<SupportMessage> Messages { get; set; } = [];
}

public sealed class SupportMessage
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("sender")] public string Sender { get; set; } = "";
    [JsonPropertyName("body")] public string Body { get; set; } = "";
    [JsonPropertyName("type")] public string Type { get; set; } = "text";
    [JsonPropertyName("created_at")] public string CreatedAt { get; set; } = "";
    [JsonPropertyName("seen")] public bool Seen { get; set; }
    [JsonIgnore] public string SenderLabel => Sender == "operator" ? "پشتیبانی BlueVPN" : "شما";
}

public sealed class SupportActionResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("message_id")] public int MessageId { get; set; }
}

public sealed class SupportUnreadResponse
{
    [JsonPropertyName("unread")] public int Unread { get; set; }
}
