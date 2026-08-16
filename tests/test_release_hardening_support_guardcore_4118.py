import pathlib
import unittest

ROOT=pathlib.Path(__file__).resolve().parents[1]

class ReleaseHardeningSupportGuardCore4118(unittest.TestCase):
    def text(self,p):
        return (ROOT/p).read_text()


    def test_support_tables_are_part_of_canonical_backup_inventory(self):
        db=self.text("bluevpn-manager/includes/class-bluevpn-db.php")
        production=self.text("bluevpn-manager/includes/class-bluevpn-production.php")
        for name in [
            "support_departments","support_topics","support_operators",
            "support_conversations","support_messages","support_events",
            "support_attachments","support_notes","support_canned_replies",
        ]:
            self.assertIn("'"+name+"'",db)
        self.assertIn("'bluevpn_support_schema'",production)

    def test_support_schema_has_topics_and_idempotency_keys(self):
        s=self.text("bluevpn-manager/includes/class-bluevpn-support.php")
        self.assertIn("private const SCHEMA = '1.2.0'",s)
        self.assertIn("self::table('topics')",s)
        self.assertIn("topic_id bigint unsigned NOT NULL DEFAULT 0",s)
        self.assertIn("client_request_id varchar(64) NULL",s)
        self.assertIn("client_message_id varchar(64) NULL",s)
        self.assertIn("uq_support_create_request (customer_id,client_request_id)",s)
        self.assertIn("uq_support_client_message (conversation_id,client_message_id)",s)

    def test_support_departments_return_nested_topics(self):
        s=self.text("bluevpn-manager/includes/class-bluevpn-support.php")
        block=s[s.index("public static function api_departments"):
                s.index("public static function api_conversations")]
        self.assertIn("SELECT id,department_id,name,slug,description,priority",block)
        self.assertIn("'topics'=>$topicMap[$id]??[]",block)
        self.assertIn("support_departments",block)

    def test_support_create_validates_topic_and_is_idempotent(self):
        s=self.text("bluevpn-manager/includes/class-bluevpn-support.php")
        block=s[s.index("public static function api_create"):
                s.index("public static function api_messages")]
        self.assertIn("SUPPORT_INVALID_TOPIC",block)
        self.assertIn("client_request_id",block)
        self.assertIn("'duplicate'=>true",block)
        self.assertIn("topic_id'=>$topicId",block)
        self.assertIn("Backward compatibility",block)
        self.assertIn("ORDER BY sort_order,id LIMIT 1",block)

    def test_support_send_is_idempotent_including_insert_race(self):
        s=self.text("bluevpn-manager/includes/class-bluevpn-support.php")
        block=s[s.index("public static function api_send"):
                s.index("public static function api_unread")]
        self.assertIn("client_message_id",block)
        self.assertGreaterEqual(block.count("'duplicate'=>true"),2)
        self.assertIn("if($mid<=0)",block)


    def test_support_conversation_list_avoids_n_plus_one_metadata_queries(self):
        support=self.text("bluevpn-manager/includes/class-bluevpn-support.php")
        block=support[support.index("public static function api_conversations"):
                      support.index("private static function auto_assign_operator")]
        self.assertIn("LEFT JOIN \".self::table('departments')",block)
        self.assertIn("LEFT JOIN \".self::table('topics')",block)
        self.assertIn("LEFT JOIN \".self::table('operators')",block)
        serializer=support[support.index("private static function serialize_conversation"):
                           support.index("public static function api_departments")]
        self.assertIn("array_key_exists('department_name',$c)",serializer)
        self.assertIn("array_key_exists('topic_name',$c)",serializer)
        self.assertIn("array_key_exists('operator_name',$c)",serializer)

    def test_support_operator_transfer_is_server_validated(self):
        s=self.text("bluevpn-manager/includes/class-bluevpn-support.php")
        block=s[s.index("public static function admin_assign"):
                s.index("public static function admin_status")]
        self.assertIn("topic_id",block)
        self.assertIn("department_ids",block)
        self.assertIn("این اپراتور برای بخش انتخاب‌شده مجاز نیست",block)
        self.assertIn("conversation_assigned",block)

    def test_support_auto_assignment_ignores_stale_online_operator(self):
        s=self.text("bluevpn-manager/includes/class-bluevpn-support.php")
        block=s[s.index("private static function auto_assign_operator"):
                s.index("public static function api_create")]
        self.assertIn("UTC_TIMESTAMP() - INTERVAL 10 MINUTE",block)

    def test_android_topic_selection_is_department_then_topic_then_message(self):
        s=self.text("android-source/BlueVpnSupportActivity.kt")
        self.assertIn("private data class Topic(",s)
        self.assertIn("private fun showTopicChooser(department: Department)",s)
        self.assertIn("private fun selectPendingTopic(",s)
        select=s[s.index("private fun selectPendingTopic"):
                 s.index("private fun showDepartmentChooser")]
        self.assertNotIn("beginNewConversation(",select)
        send=s[s.index("private fun sendMessage"):
               s.index("private fun setComposerEnabled")]
        self.assertIn("pendingDepartmentId",send)
        self.assertIn("pendingTopicId",send)
        self.assertIn("beginNewConversation(",send)

    def test_android_support_retry_ids_prevent_duplicate_create_and_message(self):
        s=self.text("android-source/BlueVpnSupportActivity.kt")
        self.assertIn("pendingCreateRequestId",s)
        self.assertIn("client_request_id",s)
        self.assertIn("retryMessageId",s)
        self.assertIn("client_message_id",s)
        self.assertIn("UUID.randomUUID()",s)

    def test_android_topic_chooser_is_scrollable_and_handles_async_loading(self):
        s=self.text("android-source/BlueVpnSupportActivity.kt")
        self.assertIn("val chooserScroll = ScrollView(this)",s)
        self.assertIn("openChooserWhenLoaded",s)
        self.assertIn("loadingDepartments",s)
        self.assertIn("در حال دریافت بخش‌ها و موضوع‌های پشتیبانی",s)

    def test_android_topic_state_survives_recreation_and_list_race(self):
        s=self.text("android-source/BlueVpnSupportActivity.kt")
        self.assertIn("override fun onSaveInstanceState",s)
        self.assertIn("support_pending_department",s)
        self.assertIn("support_pending_topic",s)
        self.assertIn("support_draft",s)
        self.assertIn("support_retry_message_id",s)
        update=s[s.index("private fun updateEmptyVisibility"):
                 s.index("private fun scrollToBottom")]
        self.assertIn("if (pendingDepartmentId > 0)",update)
        self.assertIn("emptyState.visibility = View.GONE",update)

    def test_guardcore_missing_scan_supports_api_mode(self):
        s=self.text("bluevpn-manager/includes/class-bluevpn-providers.php")
        repair=s[s.index("public static function repair_customer_missing_providers"):
                 s.index("public static function repairable_customer_count")]
        self.assertIn("gc_find_customer_subscription",repair)
        self.assertIn("gc_provision(",repair)
        self.assertIn("guardcore_service_ids_json",repair)
        self.assertIn("attached_services_synced",repair)
        self.assertNotIn("($p['auth_mode']??'manual')!=='manual'||$global===''",repair)

    def test_guardcore_lost_mapping_discovery_is_strict(self):
        s=self.text("bluevpn-manager/includes/class-bluevpn-providers.php")
        block=s[s.index("private static function gc_find_customer_subscription"):
                s.index("private static function gc_provision")]
        self.assertIn("/api/subscriptions?search=",block)
        self.assertIn("$exact=",block)
        self.assertIn("$owned=",block)
        self.assertIn("customer_id=",block)
        self.assertNotIn("similar_text",block)

    def test_guardcore_repair_syncs_services_only_without_expiry_quota_update(self):
        s=self.text("bluevpn-manager/includes/class-bluevpn-providers.php")
        repair=s[s.index("public static function repair_customer_missing_providers"):
                 s.index("public static function repairable_customer_count")]
        marker="Repair access selection without touching quota, usage"
        self.assertIn(marker,repair)
        sub=repair[repair.index(marker):repair.index("$update['guardcore_panel_id']",repair.index(marker))]
        self.assertIn("['service_ids'=>$serviceIds]",sub)
        self.assertNotIn("limit_expire",sub)
        self.assertNotIn("limit_usage",sub)
        self.assertNotIn("reset",sub.lower())

    def test_customers_repair_ui_names_all_three_providers(self):
        s=self.text("bluevpn-manager/includes/class-bluevpn-control-center.php")
        start=s.index("همگام‌سازی اشتراک‌های گمشده Provider")
        block=s[start:start+7000]
        self.assertIn("PasarGuard، Marzban و GuardCore",block)
        self.assertIn("Service",block)
        self.assertIn("PG/MZ/GC username",block)

if __name__=="__main__":
    unittest.main()
