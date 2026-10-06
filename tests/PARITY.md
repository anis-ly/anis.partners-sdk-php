# Parity table — every .NET 1.3.0 test and its twin per language

Source: `anis-ly/anis.partners-sdk-dotnet` main df1e409, `tests/Anis.Partners.Sdk.Tests`. Each lane fills its column
with the PHP test that demonstrates the behavior, or `n/a — <reason>` when the .NET-specific behavior does not exist in PHP.

## ConfigurationTests (15)

| .NET test | PHP |
|---|---|
| `Options_bind_from_the_AnisPartners_section` | ClientOptionsTest::it_binds_camel_case_partner_settings (PSR-18 client timeout remains host-owned) |
| `Code_can_adjust_what_the_file_says` | ClientOptionsTest::it_uses_safe_defaults_and_allows_explicit_settings_to_override_them |
| `A_settings_file_that_cannot_work_fails_at_startup` | ClientOptionsTest::it_refuses_an_invalid_authority_or_lifetime_before_client_creation; ClientOptionsTest::it_requires_https_except_for_loopback_authorities |
| `A_registration_without_a_signer_says_so_when_the_client_is_resolved` | n/a — PHP clients require a signer in AnisPartnersClient::create |
| `The_hosts_own_clock_is_kept` | n/a — no host DI container; the clock is an internal client dependency |
| `The_hosts_own_nonce_source_is_kept` | n/a — PHP accepts a NonceFactory directly when constructing a client, but has no host DI registration whose factory lifetime can be preserved |
| `A_named_application_never_borrows_an_unnamed_signer` | n/a — PHP constructs each client directly; named DI clients do not apply |
| `Registering_twice_without_a_name_is_refused_at_startup` | n/a — PHP has no SDK DI registration stage |
| `A_name_can_be_registered_once` | n/a — PHP has no SDK named-client registry |
| `Named_applications_each_sign_with_their_own_key_at_their_own_authority` | n/a — separately constructed PHP clients carry their own options and signer; named DI registration does not apply |
| `Each_named_application_verifies_with_the_keys_of_its_own_authority` | n/a — each direct PHP client owns its authority-specific verifier; named DI registration does not apply |
| `Create_builds_a_signed_and_verified_client_without_a_container` | PartnerPipelineTest::it_signs_a_safe_read_without_nonce_or_content_digest |
| `An_unnamed_and_a_named_application_live_side_by_side` | n/a — PHP has no SDK DI client registry |
| `An_unknown_name_says_which_names_exist` | n/a — PHP has no SDK DI client registry |
| `A_named_application_without_a_signer_names_itself_in_the_failure` | n/a — PHP requires a signer when the client is constructed |

## ConnectionRotationTests (1)

| .NET test | PHP |
|---|---|
| `Calls_after_the_handler_lifetime_go_out_on_fresh_connections` | n/a — connection pooling and rotation belong to the supplied PSR-18 client |

## ContractDriftTests (6)

| .NET test | PHP |
|---|---|
| `The_sdk_route_table_matches_the_published_surface_exactly` | ContractDriftTest::it_matches_the_published_route_table_in_both_directions |
| `Every_route_signs_under_the_profile_the_contract_assigns_it` | ContractDriftTest::it_uses_the_request_kind_assigned_to_each_route |
| `Every_public_error_code_in_the_catalogue_is_known_to_the_sdk` | ContractDriftTest::it_knows_every_public_error_code_in_the_catalogue |
| `The_key_submission_answer_model_carries_exactly_the_published_members` | ContractDriftTest::it_carries_exactly_the_published_key_submission_members |
| `A_released_error_code_keeps_its_number` | ContractDriftTest::it_generates_the_error_enum_from_the_current_catalogue (PHP preserves public string values; the .NET enum-number check has no PHP equivalent) |
| `The_covered_component_profiles_match_the_contracts_description` | ContractDriftTest::it_keeps_the_covered_signature_components_in_contract_order |

## EnrollmentTests (15)

| .NET test | PHP |
|---|---|
| `The_sdk_builds_the_proof_Commands_verifies` | EnrollmentProofVectorTest::it_builds_and_verifies_an_enrollment_proof_vector |
| `A_der_signature_is_refused_before_it_reaches_Anis` | EnrollmentClientTest::it_refuses_a_der_proof_before_sending_it |
| `A_key_on_another_curve_is_refused` | EnrollmentClientTest::it_refuses_a_different_curve_before_contacting_anis |
| `A_submission_result_without_its_challenge_cannot_be_proved` | EnrollmentClientTest::it_requires_the_submitted_challenge_before_proving_possession |
| `Submit_then_prove_walks_the_enrollment_routes_and_verifies_every_answer` | EnrollmentClientTest::it_submits_a_key_then_proves_possession_on_verified_enrollment_routes |
| `A_refused_step_is_an_enrollment_exception` | EnrollmentClientTest::it_surfaces_a_verified_enrollment_refusal_as_a_typed_error |
| `An_enrollment_call_is_measured_like_every_other_call` | EnrollmentClientTest::it_submits_a_key_then_proves_possession_on_verified_enrollment_routes (transport uses the shared telemetry pipeline) |
| `The_status_read_carries_the_key_end_date_and_tolerates_its_absence` | EnrollmentClientTest::it_reads_an_optional_key_end_date_in_enrollment_status |
| `The_sdk_computes_the_thumbprint_Commands_computed` | EnrollmentClientTest::it_submits_a_key_then_proves_possession_on_verified_enrollment_routes |
| `A_jwk_that_is_not_a_complete_p256_public_key_has_no_thumbprint` | EnrollmentClientTest::it_refuses_a_different_curve_before_contacting_anis |
| `The_private_member_does_not_change_the_thumbprint` | EnrollmentClientTest::it_ignores_a_private_jwk_member_when_fingerprinting_a_public_key |
| `The_safety_code_is_derived_from_the_verified_thumbprint_never_taken_from_the_answer` | EnrollmentClientTest::it_submits_a_key_then_proves_possession_on_verified_enrollment_routes |
| `A_key_Anis_answers_for_with_another_thumbprint_stops_the_enrollment_before_any_proof` | EnrollmentClientTest::it_stops_when_anis_reports_a_different_key_thumbprint |
| `An_answer_without_a_thumbprint_is_a_mismatch_too` | EnrollmentClientTest::it_treats_an_answer_without_a_thumbprint_as_a_key_mismatch |
| `A_key_the_sdk_cannot_fingerprint_is_refused_before_anything_is_sent` | EnrollmentClientTest::it_refuses_a_different_curve_before_contacting_anis |

## ErrorMappingTests (5)

| .NET test | PHP |
|---|---|
| `Every_public_code_has_a_decision` | ErrorMappingTest::it_has_a_decision_for_every_published_code |
| `A_code_becomes_its_decided_exception_and_outcome` | ErrorMappingTest::it_maps_a_code_to_its_typed_exception_and_order_outcome (data provider) |
| `A_code_this_version_does_not_know_is_treated_as_an_open_order` | ErrorMappingTest::it_keeps_an_unknown_future_code_open_for_order_recovery |
| `A_replayed_refusal_is_a_closed_order_whatever_its_code` | ErrorMappingTest::it_closes_a_replayed_refusal_regardless_of_its_code (data provider) |
| `A_signed_body_that_is_not_a_problem_is_still_a_refusal` | ErrorMappingTest::it_turns_an_unreadable_signed_problem_into_a_refusal |

## HostRetryTests (1)

| .NET test | PHP |
|---|---|
| `A_retry_handler_from_the_host_defaults_resends_one_fresh_signature_per_attempt` | PartnerPipelineTest::it_creates_a_fresh_signature_for_each_caller_retry (no SDK-level retry is added) |

## ModelFieldTests (10)

| .NET test | PHP |
|---|---|
| `A_subcategory_reads_its_disclaimer` | ModelFieldTest::it_reads_a_subcategory_disclaimer |
| `A_subcategory_without_a_disclaimer_has_none` | ModelFieldTest::it_leaves_an_absent_subcategory_disclaimer_null |
| `A_catalogue_card_reads_its_quantity_limits` | ModelFieldTest::it_reads_catalogue_card_quantity_limits |
| `A_catalogue_card_without_quantity_limits_has_none` | ModelFieldTest::it_leaves_absent_catalogue_card_quantity_limits_null |
| `An_order_reads_its_reference_failure_code_and_withheld_flag` | ModelFieldTest::it_reads_order_reference_failure_code_and_withheld_flag |
| `An_order_without_the_new_members_leaves_them_null` | ModelFieldTest::it_leaves_new_order_members_null_when_absent |
| `A_revealed_credential_reads_its_expiry_and_reveal_details` | ModelFieldTest::it_reads_revealed_credential_expiry_and_reveal_details |
| `A_revealed_credential_without_the_new_members_leaves_them_null` | ModelFieldTest::it_leaves_new_revealed_credential_members_null_when_absent |
| `A_masked_card_reads_its_price_expiry_invoice_number_face_value_and_subcategory` | ModelFieldTest::it_reads_masked_card_price_expiry_invoice_and_catalogue_details |
| `A_masked_card_without_the_new_members_leaves_them_null` | ModelFieldTest::it_leaves_new_masked_card_members_null_when_absent |

## ObservabilityTests (14)

| .NET test | PHP |
|---|---|
| `A_call_produces_a_span_tagged_with_the_route_TEMPLATE_not_the_path` | ObservabilityTest::it_records_the_route_template_and_separate_request_and_signature_durations |
| `Duration_and_signing_cost_are_measured_separately` | ObservabilityTest::it_records_the_route_template_and_separate_request_and_signature_durations |
| `An_order_reports_its_outcome_and_carries_the_operation_id_on_the_span` | ObservabilityTest::it_records_non_empty_telemetry_without_revealing_order_secrets; ObservabilityTest::it_returns_verified_order_credentials_when_the_host_logger_throws |
| `A_refusal_is_one_warning_carrying_the_code_and_the_request_id` | ObservabilityTest::it_logs_a_verified_refusal_with_its_code_and_request_id |
| `A_discarded_response_is_counted_by_the_rule_that_refused_it` | ObservabilityTest::it_counts_and_logs_an_unverifiable_response_before_discarding_it |
| `A_timed_out_order_is_a_failed_span_a_measured_call_and_an_unknown_outcome` | n/a — PSR-18 defines no portable timeout signal; no-answer behavior is tested as unknown |
| `A_lost_connection_on_an_order_is_counted_unknown` | ObservabilityTest::it_counts_an_order_without_a_usable_answer_as_unknown |
| `An_unverifiable_order_answer_is_counted_unknown` | ObservabilityTest::it_counts_and_logs_an_unverifiable_response_before_discarding_it |
| `A_refusal_that_reached_no_decision_is_counted_unknown_and_a_final_one_is_not` | ObservabilityTest::it_does_not_count_a_final_refusal_as_an_unknown_order |
| `A_refusal_of_access_on_create_is_counted_unknown_and_logged` | ObservabilityTest::it_counts_an_access_refusal_on_create_as_unknown_and_logs_it |
| `A_failing_signer_is_named_as_such_and_is_not_an_unknown_order` | ObservabilityTest::it_names_a_signing_failure_without_counting_an_unknown_order |
| `A_verified_success_with_an_empty_body_is_counted_unknown` | ObservabilityTest::it_reports_empty_success_bodies_as_unknown_with_an_internal_error |
| `A_replayed_refusal_is_not_counted_as_an_unknown_order` | ObservabilityTest::it_logs_a_verified_refusal_with_its_code_and_request_id |
| `No_secret_reaches_any_telemetry_signal` | ObservabilityTest::it_records_non_empty_telemetry_without_revealing_order_secrets; ObservabilityTest::it_keeps_known_nonce_and_signature_base_out_of_signing_exception_chains |

## OrderOutcomeTests (25)

| .NET test | PHP |
|---|---|
| `A_201_is_completed_and_carries_the_credentials` | OrderOperationsTest::it_returns_completion_credentials_even_when_the_response_is_marked_replayed |
| `A_completion_whose_codes_are_withheld_is_completed_with_no_credentials` | OrderOperationsTest::it_treats_a_completion_without_credentials_as_codes_withheld |
| `A_completion_that_says_its_codes_are_withheld_is_withheld_and_carries_the_reference` | OrderOperationsTest::it_preserves_the_withheld_flag_and_external_reference |
| `The_withheld_flag_alone_makes_the_completion_withheld` | OrderOperationsTest::it_classifies_signed_order_answers (data provider) |
| `A_completion_with_credentials_and_no_flag_is_not_withheld` | OrderOperationsTest::it_classifies_signed_order_answers (data provider) |
| `A_repeat_after_completion_is_replayed_and_carries_no_credentials` | OrderOperationsTest::it_classifies_signed_order_answers (data provider); OrderOperationsTest::it_classifies_each_true_replay_header_as_replayed |
| `A_resume_that_recovers_the_completion_returns_the_credentials` | OrderOperationsTest::it_classifies_signed_order_answers (data provider) |
| `A_recorded_refusal_says_it_was_replayed_and_that_nothing_was_placed` | OrderOperationsTest::it_classifies_signed_order_answers (data provider) |
| `A_fresh_refusal_is_not_marked_replayed` | OrderOperationsTest::it_classifies_signed_order_answers (data provider) |
| `An_unavailable_dependency_leaves_the_order_open_for_a_resume` | OrderOperationsTest::it_keeps_a_dependency_refusal_open_for_recovery |
| `A_202_is_processing_and_reports_when_to_resume` | OrderOperationsTest::it_classifies_signed_order_answers (data provider) |
| `A_price_change_is_not_placed_and_carries_its_typed_refusal` | OrderOperationsTest::it_keeps_a_price_change_as_a_typed_closed_refusal |
| `A_rate_limited_create_leaves_the_order_open_and_carries_the_signed_retry_after` | OrderOperationsTest::it_uses_retry_after_when_a_create_is_rate_limited |
| `A_rate_limited_create_without_a_retry_after_suggests_the_default_delay` | OrderOperationsTest::it_classifies_signed_order_answers (data provider) |
| `A_fresh_refusal_of_a_resume_leaves_the_order_open` | OrderOperationsTest::it_keeps_a_fresh_refusal_during_resume_unknown |
| `A_refusal_at_the_door_on_create_leaves_the_order_open_and_suggests_a_minute` | OrderOperationsTest::it_uses_a_minute_to_recover_a_door_refusal |
| `A_refusal_at_the_door_on_resume_suggests_a_minute` | OrderOperationsTest::it_classifies_signed_order_answers (data provider) |
| `A_refusal_at_the_door_with_a_retry_after_suggests_that_wait` | OrderOperationsTest::it_classifies_signed_order_answers (data provider) |
| `A_replayed_refusal_at_the_door_is_not_placed` | OrderOperationsTest::it_closes_a_replayed_door_refusal_without_resuming_it |
| `A_unit_price_that_is_not_positive_never_leaves_the_process` | OrderOperationsTest::it_refuses_non_positive_prices_before_opening_the_wire (uses `-0.001` to verify the positive-price guard; the .NET case also includes `0.0004`, which rounds to zero in .NET, while the Money ruling requires refusing any amount with more than three decimal places) |
| `A_timeout_leaves_the_order_open_on_create_and_on_resume` | OrderOperationsTest::it_keeps_create_and_resume_unknown_after_a_lost_answer |
| `An_unexpected_failure_while_sending_leaves_the_order_open` | OrderOperationsTest::it_keeps_create_and_resume_unknown_after_a_lost_answer |
| `A_call_the_caller_cancelled_is_rethrown` | n/a — the synchronous PSR-18 API has no cancellation token parameter |
| `A_verified_success_with_invalid_order_json_leaves_the_order_open` | OrderOperationsTest::it_keeps_a_verified_success_with_unreadable_json_unknown |
| `A_total_that_is_not_unit_times_quantity_never_leaves_the_process` | OrderOperationsTest::it_refuses_a_total_that_does_not_match_before_opening_the_wire |

## PagingTests (4)

| .NET test | PHP |
|---|---|
| `ListAsync_follows_the_cursor_and_signs_the_query_it_transmits` | PartnerPipelineTest::it_follows_every_page_and_signs_the_encoded_cursor_it_sends |
| `Owned_cards_page_the_same_way` | PartnerPipelineTest::it_walks_owned_card_pages_until_the_cursor_is_empty |
| `Catalogue_cards_page_the_same_way` | PartnerPipelineTest::it_walks_catalogue_card_pages_for_a_wallet_and_subcategory |
| `Catalogue_categories_and_subcategories_page_the_same_way` | PartnerPipelineTest::it_walks_category_and_subcategory_pages |

## PipelineBehaviourTests (8)

| .NET test | PHP |
|---|---|
| `A_safe_read_carries_a_signature_and_no_nonce_or_digest` | PartnerPipelineTest::it_signs_a_safe_read_without_nonce_or_content_digest; PartnerPipelineTest::it_signs_the_constructed_https_request_authority_without_its_default_port |
| `A_reveal_transmits_zero_body_bytes_and_digests_them` | PartnerPipelineTest::it_sends_a_reveal_with_no_body_bytes_and_a_digest_of_empty_bytes |
| `An_invoice_reveal_transmits_zero_body_bytes_and_digests_them` | PartnerPipelineTest::it_sends_an_invoice_reveal_with_no_body_bytes |
| `The_signature_self_check_transmits_exactly_an_empty_object` | PartnerPipelineTest::it_sends_the_signature_check_as_an_empty_json_object |
| `An_order_carries_the_callers_idempotency_key` | PartnerPipelineTest::it_sends_the_caller_operation_id_with_the_exact_order_json |
| `A_tampered_body_is_discarded_rather_than_returned` | PartnerPipelineTest::it_refuses_to_return_a_body_changed_after_signing; PartnerResponseVerifierTest::it_discards_a_response_that_arrives_with_gzip_content_encoding; PartnerResponseVerifierTest::it_rejects_junk_after_an_otherwise_valid_signature_field |
| `A_credential_never_reaches_a_log_through_ToString` | SecretRedactionTest::it_redacts_credentials_nested_in_an_order_and_invoice_collection (also checks var_dump/print_r; __toString remains redacted) |
| `Money_multiplies_exactly_and_renders_at_scale_three` | MoneyTest::it_multiplies_exactly_and_clears_the_balance_timestamp |

## RequestVectorTests (1)

| .NET test | PHP |
|---|---|
| `The_sdk_reproduces_the_vector_base_exactly` | RequestVectorTest::it_reproduces_a_request_vector_and_signs_with_p1363 (data provider) |

## ResponseVectorTests (1)

| .NET test | PHP |
|---|---|
| `The_verifier_reaches_the_declared_outcome` | ResponseVectorTest::it_reaches_the_declared_outcome_for_a_response_vector (data provider) |

## SafetyCodeVectorTests (4)

| .NET test | PHP |
|---|---|
| `The_key_has_the_declared_thumbprint_and_code` | SafetyCodeVectorTest::it_computes_one_safety_code_vector (data provider) |
| `Every_entry_matches_or_does_not_as_declared` | SafetyCodeVectorTest::it_computes_one_safety_code_vector (data provider) |
| `The_manifest_lists_exactly_the_vectors_on_disk` | SafetyCodeVectorTest::it_matches_the_manifest_to_vectors_on_disk |
| `Only_a_thumbprint_has_a_safety_code` | SafetyCodeVectorTest::it_refuses_to_derive_a_code_from_an_invalid_thumbprint (data provider) |


## Additional PHP guarantees

| Review finding | PHP regression test or documentation |
|---|---|
| Verified order result survives throwing telemetry | `ObservabilityTest::it_returns_verified_order_credentials_when_the_host_logger_throws` |
| Repeated replay markers are individually classified on success and refusal | `OrderOperationsTest::it_classifies_each_true_replay_header_as_replayed`; `OrderOperationsTest::it_treats_each_true_replay_header_on_a_refusal_as_not_placed` |
| Native inspection redacts credentials, signing material, and enrollment tokens | `SecretRedactionTest`; `EnrollmentClientTest::it_redacts_an_enrollment_token_from_native_client_inspection` |
| Identity encoding and direct 3xx responses | `PartnerPipelineTest::it_signs_a_safe_read_without_nonce_or_content_digest`; `PartnerPipelineTest::it_does_not_follow_a_signed_redirect_answer`; `HttpSigningKeySourceTest` |
| Throwing telemetry dependencies cannot change operation results | `ObservabilityTest::it_preserves_each_result_when_a_host_signal_throws` (logger, meter, and tracer across read, reveal, completed/unknown order, refusal, enrollment, and key fetch) |
| Noncanonical encodings and trailing signature text are refused | `Base64UrlTest::it_refuses_noncanonical_base64url_padding_bits`; `PartnerResponseVerifierTest::it_rejects_junk_after_an_otherwise_valid_signature_field` |
| Signing-key cache write access is part of the verification trust boundary | `docs/caching.md` |
| Exact scalar types survive weak PHP callers | `MoneyTest::it_refuses_a_weakly_coerced_fractional_string_as_quantity`; `MoneyTest::it_refuses_weakly_coerced_text_as_debt_consent`; `MoneyTest::it_refuses_weakly_coerced_money_thousandths`; `MoneyTest::it_refuses_a_weakly_coerced_money_multiplication_quantity` |
| Models serialize with wire member names and UTC date strings | `ModelSerializationTest::it_serializes_money_as_its_decimal_wire_members`; `ModelSerializationTest::it_serializes_date_bearing_response_models_as_utc_wire_strings`; `ModelSerializationTest::it_serializes_nested_money_and_status_values_as_wire_members` |
| Enrollment dry-run keeps generated private keys in memory, while real enrollment persists owner-only PEM first | `SampleTest::enrollment_dry_run_never_creates_a_private_key_file`; `SampleTest::a_real_enrollment_key_file_is_written_with_owner_only_permissions` |
| The sample writes intent before send and resumes only from the stored operation id | `SampleTest::it_records_order_intent_before_invoking_the_order_sender`; `SampleTest::resume_uses_the_journal_operation_id_and_request` |
| The sample's dry-run path never calls the underlying HTTP client | `SampleTest::dry_run_wire_never_calls_the_underlying_http_client` |
| A later unknown result preserves credentials stored by an earlier completion | `SampleTest::an_unknown_resume_keeps_credentials_already_saved_in_the_journal` |
| PHP examples resolve classes and methods as well as parsing | `MarkdownPhpExamplesTest::it_syntax_and_phpstan_checks_every_php_fence_in_the_readme_and_docs` (runs PHPStan level max on each extracted block with typed stubs) |

The response-signature parser intentionally rejects trailing text and noncanonical base64, although the current .NET
parser accepts trailing text. The HTTPS rule also tightens the currently inspected .NET options, which still permit
non-loopback HTTP. PHP follows the review rule for both cases; no other wire contract changed.
