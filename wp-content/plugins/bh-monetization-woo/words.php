<?php
/**
 * EVERY WORD PEOPLE SEE WHEN BUYING, TIPPING OR JOINING A SUPPORTER TIER.
 * (The "Get the course" button on a course's sales page is in bh-courses/words.php.)
 *
 * To change wording, edit the text on the right of the arrow and save. Keep the
 * key on the left exactly as it is, and keep the quote marks around the text.
 * Write an apostrophe as \' (for example 'You\'re now supporting').
 * Words in {curly braces} get filled in for you; keep them in your text.
 */
if (!defined('ABSPATH')) exit;

return [
    /* ---------- After checkout (order received page) ---------- */
    'receipt.title'             => 'Your purchase is in your account.',
    'receipt.text'              => 'Log in as {email} to start. If this is your first purchase, we\'ve emailed you a link to set your password.',
    'receipt.link'              => 'Go to your account →',

    /* ---------- Supporter tiers page ---------- */
    'tiers.title'               => 'Supporter tiers',
    'tiers.billing_recurring'   => 'Recurring — billed monthly',
    'tiers.billing_one_time'    => 'One-time — 30 days access',
    'tiers.current'             => 'Your current tier',
    'tiers.join_monthly'        => 'Join monthly',
    'tiers.join_annually'       => 'Join annually',
    'tiers.switch'              => 'Switch to this tier',
    'tiers.switch_annual'       => 'Switch (annual)',
    'tiers.gift'                => 'Gift this tier',
    'tiers.gift_email'          => 'Recipient\'s email',
    'tiers.gift_send'           => 'Send gift',
    'tiers.empty_lead'          => 'Supporter tiers aren\'t open yet.',
    'tiers.empty_note'          => 'This is where you\'ll be able to back the music directly — a recurring pledge in exchange for members-only perks. Check back soon.',
    'tiers.unavailable_lead'    => 'Supporter tiers aren\'t available yet.',
    'tiers.unavailable_note'    => 'The store connection still needs to be finished before tiers can go live.',
    'tiers.thanks_title'        => '🎉 You\'re now supporting at {tier}!',
    'tiers.thanks_unlocks'      => 'Here\'s what that unlocks:',
    'tiers.manage'              => 'Manage your membership →',

    /* Subscriptions (only with WooCommerce Subscriptions) */
    'subscription.paused'       => 'Your subscription is paused — you\'ll keep your account, but supporter access is off until you resume.',
    'subscription.resume'       => 'Resume subscription',
    'subscription.pause'        => 'Pause subscription',
    'subscription.pause_confirm' => 'Pause your subscription? You\'ll lose supporter access until you resume it.',

    /* ---------- Members-only content ---------- */
    'paywall.generic'           => 'This content requires supporter access.',
    'paywall.tier'              => 'This content is for {tier} supporters and above.',
    'paywall.button'            => 'Become a supporter',

    /* ---------- Tip jar and buy buttons ---------- */
    'tip.label'                 => 'Send a tip:',
    'tip.send'                  => 'Send Tip',
    'buy.button'                => 'Buy',
    'buy.for_price'             => 'Buy for ${price}',
    'buy.name_price'            => 'Name your price (min ${min}):',

    /* ---------- "Membership & Wallet" in the account area ---------- */
    'portal.tab'                => 'Membership & Wallet',
    'portal.heading'            => 'Membership & Wallet',
    'portal.active_tiers'       => 'Active tiers',
    'portal.change_tier'        => 'Change tier',
    'portal.wallet'             => 'Wallet',
    'portal.wallet_empty'       => 'No wallet activity yet.',
    /* =================================================================
     * ADMIN SCREENS (wp-admin). Some text keeps small bits of HTML from the
     * original, like <code> or &rarr; (an arrow); leave those as they are.
     * ================================================================= */

    /* ---------- admin (includes/class-admin.php) ---------- */
    'admin.admin.monetization_settings'                    => 'Monetization Settings',
    'admin.admin.woocommerce_isn_t_installed_yet'          => 'WooCommerce isn\'t installed yet.',
    'admin.admin.every_monetization_feature_tiers_purchas' => 'Every monetization feature (tiers, purchases, tips, pay-per-play) stays completely inactive — zero cost, zero UI clutter on your track/release screens — until you install it. Go to <strong>The Self-Hosted Self</strong> and click "Install from WordPress.org" next to WooCommerce.',
    'admin.admin.woocommerce_active'                       => 'WooCommerce is active.',
    'admin.admin.woocommerce_subscriptions_also_active_su' => 'WooCommerce Subscriptions is also active — supporter tiers bill on a real recurring schedule.',
    'admin.admin.woocommerce_subscriptions_isn_t_active'   => 'WooCommerce Subscriptions isn\'t active',
    'admin.admin.supporter_tiers_will_sell_as'             => '— supporter tiers will sell as one-time, 30-day access instead of automatic recurring billing. Install WooCommerce Subscriptions (a separate, official, paid extension — WooCommerce core has no subscription billing of its own) if you want true recurring tiers.',
    'admin.admin.pay_per_play_wallet_top'                  => 'Pay-per-play wallet top-up amounts',
    'admin.admin.save'                                     => 'Save',
    'admin.admin.supporter_tiers_page'                     => 'Supporter tiers page',
    'admin.admin.add_bhm_tiers_shortcode_page'             => 'Add the <code>[bhm_tiers]</code> shortcode to a page, then set it below so paywall notices link somewhere real.',
    'admin.admin.gift_redemption_page'                     => 'Gift redemption page',
    'admin.admin.add_bhm_redeem_gift_shortcode'            => 'Add the <code>[bhm_redeem_gift]</code> shortcode to a page so a gift recipient has somewhere to claim their tier.',
    'admin.admin.not_set_yet_gift_claim'                   => 'Not set yet — gift claim links currently have nowhere to send a recipient.',
    'admin.admin.recent_gift_redemptions'                  => 'Recent gift redemptions',
    'admin.admin.no_gifts_purchased_yet'                   => 'No gifts purchased yet.',
    'admin.admin.recipient'                                => 'Recipient',
    'admin.admin.status'                                   => 'Status',
    'admin.admin.purchased'                                => 'Purchased',
    'admin.admin.claimed'                                  => 'Claimed',
    'admin.admin.active_payment_method'                    => 'Active payment method',
    'admin.admin.manage_payment_methods'                   => 'Manage payment methods',
    'admin.admin.tiers_purchases_can_created_but'          => 'Tiers and purchases can be created, but a fan can\'t actually pay for anything until at least one gateway (Stripe, PayPal, WooCommerce Payments, etc.) is turned on.',
    'admin.admin.opens_woocommerce_s_own_guided'           => 'Opens WooCommerce\'s own guided payments setup — real card processing is configured there, not duplicated here.',

    /* ---------- auto coupons (includes/class-auto-coupons.php) ---------- */
    'admin.auto_coupons.apply_automatically'           => 'Apply automatically',
    'admin.auto_coupons.added_every_cart_coupon_valid' => 'Added to every cart this coupon is valid for. Use the usage limit for offers like "first 10 buyers".',

    /* ---------- crm integration (includes/class-crm-integration.php) ---------- */
    'admin.crm_integration.access_revoked_fan_has_been'            => 'Access revoked — the fan has been notified.',
    'admin.crm_integration.no_active_tier'                         => 'No active tier',
    'admin.crm_integration.shared_device_with_another_refunding'   => '— ⚠ shared device with another refunding account',
    'admin.crm_integration.unusual_play_rate_burst_detected'       => '— ⚠ unusual play-rate burst detected',
    'admin.crm_integration.unusual_wallet_top_up_volume'           => '— ⚠ unusual wallet top-up volume',
    'admin.crm_integration.bh_monetization'                        => 'BH Monetization',
    'admin.crm_integration.wallet_balance'                         => 'Wallet balance:',
    'admin.crm_integration.repeated_refund_pattern_worth_human'    => '— repeated-refund pattern, worth a human look before extending further trust (e.g. wallet top-ups).',
    'admin.crm_integration.account_s_device_connection_matches'    => '⚠ This account\'s device/connection matches another account with a recent refund — possible same-person, new-account evasion. Worth a look, not proof on its own (shared networks/households do happen).',
    'admin.crm_integration.unusually_fast_burst_paid_plays'        => '⚠ An unusually fast burst of paid plays was detected recently — could be normal heavy use, or a compromised account/payment method being tested. Worth a look.',
    'admin.crm_integration.type'                                   => 'Type',
    'admin.crm_integration.what'                                   => 'What',
    'admin.crm_integration.granted'                                => 'Granted',
    'admin.crm_integration.expires'                                => 'Expires',
    'admin.crm_integration.tier'                                   => 'Tier #',
    'admin.crm_integration.revoke'                                 => 'Revoke',
    'admin.crm_integration.active_supporters'                      => 'Active supporters',
    'admin.crm_integration.current_subscription_tier_entitlements' => 'Current subscription/tier entitlements',
    'admin.crm_integration.new_entitlements'                       => 'New entitlements',
    'admin.crm_integration.purchases_tier_grants_last_30'          => 'Purchases + tier grants, last 30 days',

    /* ---------- downloads (includes/class-downloads.php) ---------- */
    'admin.downloads.video' => '— Video',

    /* ---------- entitlements (includes/class-entitlements.php) ---------- */
    'admin.entitlements.refund_adjusted_account'                  => 'A refund adjusted your account',
    'admin.entitlements.order'                                    => 'Order #',
    'admin.entitlements.was_refunded_cancelled_any_access'        => 'was refunded or cancelled, and any access or wallet credit it granted has been reversed accordingly.',
    'admin.entitlements.subscription_paused'                      => 'Your subscription is paused',
    'admin.entitlements.subscription_has_ended'                   => 'Your subscription has ended',
    'admin.entitlements.supporter_access_hold_while_subscription' => 'Your supporter access is on hold while your subscription is paused. Resume it any time to pick back up right where you left off.',
    'admin.entitlements.supporter_subscription_has_ended_access'  => 'Your supporter subscription has ended, and the access it granted has been removed.',
    'admin.entitlements.access_was_updated'                       => 'Your access was updated',
    'admin.entitlements.administrator_removed_access_previously_' => 'An administrator removed access previously granted to your account.',
    'admin.entitlements.supporter_tier_changed'                   => 'Your supporter tier changed',
    'admin.entitlements.re_now'                                   => 'You\'re now a',
    'admin.entitlements.switched_from'                            => 'You switched from',
    'admin.entitlements.any_unused_time_old_tier'                 => 'Any unused time on your old tier was credited to your wallet.',
    'admin.entitlements.upgraded_from'                            => 'You upgraded from',
    'admin.entitlements.access_granted'                           => 'Access granted',
    'admin.entitlements.s_account_whenever_want'                  => 'It\'s in your account whenever you want it.',
    'admin.entitlements.thanks_support_access_active_now'         => 'Thanks for your support — your access is active now.',

    /* ---------- frontend (includes/class-frontend.php) ---------- */
    'admin.frontend.supporter_tiers'                  => 'Supporter Tiers',
    'admin.frontend.only_can_see'                     => '(only you can see this)',
    'admin.frontend.save'                             => '— save',
    'admin.frontend.invalid_request'                  => 'Invalid request.',
    'admin.frontend.subscriptions_aren_t_available'   => 'Subscriptions aren\'t available.',
    'admin.frontend.subscription_doesn_t_belong'      => 'That subscription doesn\'t belong to you.',
    'admin.frontend.paused_by_subscriber_from_their'  => 'Paused by the subscriber from their account.',
    'admin.frontend.resumed_by_subscriber_from_their' => 'Resumed by the subscriber from their account.',
    'admin.frontend.tip'                              => 'Tip',
    'admin.frontend.log_see_play_credit_wallet'       => 'Log in to see your play-credit wallet.',

    /* ---------- gift access (includes/class-gift-access.php) ---------- */
    'admin.gift_access.recipient_email'                    => 'Recipient email',
    'admin.gift_access.message_optional_shown_their_order' => 'Message (optional, shown in their order email)',
    'admin.gift_access.gift_access'                        => 'Gift access',
    'admin.gift_access.enter_valid_email_address'          => 'Enter a valid email address.',
    'admin.gift_access.gifted'                             => 'Gifted to',
    'admin.gift_access.order'                              => '(order #',
    'admin.gift_access.item_has_no_purchase_product'       => 'This item has no purchase product yet. Set a price and save first.',
    'admin.gift_access.already_has_access'                 => 'already has access.',
    'admin.gift_access.gifted_by'                          => 'Gifted by',
    'admin.gift_access.via_gift_access'                    => 'via Gift access.',

    /* ---------- gifts (includes/class-gifts.php) ---------- */
    'admin.gifts.gift'                           => 'Gift for',
    'admin.gifts.sent_gift_membership'           => 'sent you a gift membership!',
    'admin.gifts.no_gift_code_provided'          => 'No gift code provided.',
    'admin.gifts.gift_code_isn_t_valid'          => 'That gift code isn\'t valid.',
    'admin.gifts.gift_has_already_been_claimed'  => 'This gift has already been claimed.',
    'admin.gifts.log_create_account_claim_gift'  => 'Log in or create an account to claim it — your gift will still be here once you do.',
    'admin.gifts.claim_gift'                     => 'Claim this gift',
    'admin.gifts.gift_no_longer_available_claim' => 'That gift is no longer available to claim.',

    /* ---------- guest buyers (includes/class-guest-buyers.php) ---------- */
    'admin.guest_buyers.guest_purchase_attached_existing_account' => 'Guest purchase attached to the existing account for',
    'admin.guest_buyers.guest_purchase_created_account'           => 'Guest purchase: created an account for',
    'admin.guest_buyers.sent_set_password_email'                  => 'and sent a set-password email.',

    /* ---------- portal panel (includes/class-portal-panel.php) ---------- */
    'admin.portal_panel.when'   => 'When',
    'admin.portal_panel.amount' => 'Amount',
    'admin.portal_panel.reason' => 'Reason',

    /* ---------- post gate (includes/class-post-gate.php) ---------- */
    'admin.post_gate.require_supporter_tier_read'       => 'Require a supporter tier to read this',
    'admin.post_gate.requires_tier_selected_here_any'   => 'Requires the tier selected here OR any higher-priced tier — same price-rank rule bh-courses\' own tier gate uses.',
    'admin.post_gate.open_everyone'                     => '— Open to everyone —',
    'admin.post_gate.no_tiers_created_yet_see'          => 'No tiers created yet — see Supporter Tiers.',
    'admin.post_gate.visitor_without_tier_sees_paywall' => 'A visitor without this tier sees a paywall notice instead of the real content.',

    /* ---------- product sync (includes/class-product-sync.php) ---------- */
    'admin.product_sync.supporter_tier'        => '— Supporter Tier',
    'admin.product_sync.supporter_tier_annual' => '— Supporter Tier (Annual)',

    /* ---------- recommendations (includes/class-recommendations.php) ---------- */
    'admin.recommendations.may_also_like' => 'You may also like',

    /* ---------- referrals (includes/class-referrals.php) ---------- */
    'admin.referrals.referrals'                  => 'Referrals',
    'admin.referrals.share_code_anyone_who_uses' => 'Share your code — anyone who uses it gets',
    'admin.referrals.off_earn'                   => '% off, and you earn',
    'admin.referrals.what_they_spend_as_wallet'  => '% of what they spend as wallet credit.',

    /* ---------- storefront (includes/class-storefront.php) ---------- */
    'admin.storefront.collections'                            => 'Collections',
    'admin.storefront.collection'                             => 'Collection',
    'admin.storefront.bh_storefront'                          => 'BH Storefront',
    'admin.storefront.collection_doesn_t_exist'               => 'That collection doesn\'t exist.',
    'admin.storefront.woocommerce_isn_t_active_nothing'       => 'WooCommerce isn\'t active — nothing to show here yet.',
    'admin.storefront.category'                               => 'Category',
    'admin.storefront.all'                                    => 'All',
    'admin.storefront.min_price'                              => 'Min price',
    'admin.storefront.max_price'                              => 'Max price',
    'admin.storefront.stock_only'                             => 'In stock only',
    'admin.storefront.apply'                                  => 'Apply',
    'admin.storefront.no_products_match_filters'              => 'No products match your filters',
    'admin.storefront.no_products_yet'                        => 'No products yet',
    'admin.storefront.products_publish_woocommerce_will_show' => 'Products you publish in WooCommerce will show up here.',
    'admin.storefront.no_products_found'                      => 'No products found',
    'admin.storefront.matching_filters'                       => 'matching your filters.',
    'admin.storefront.woocommerce_unavailable'                => 'WooCommerce is unavailable.',
    'admin.storefront.product_filter'                         => 'Product Filter',
    'admin.storefront.related_products'                       => 'Related Products',

    /* ---------- style surface (includes/class-style-surface.php) ---------- */
    'admin.style_surface.fan'                                  => 'Fan',
    'admin.style_surface.early_access_new_releases'            => 'Early access to new releases.',
    'admin.style_surface.join'                                 => 'Join',
    'admin.style_surface.supporter'                            => 'Supporter',
    'admin.style_surface.everything_fan_plus_exclusive_tracks' => 'Everything in Fan, plus exclusive tracks and streaming-library access.',
    'admin.style_surface.current_tier'                         => 'Your current tier',
    'admin.style_surface.content_supporter_supporters_above'   => 'This content is for <strong>Supporter</strong> supporters and above.',
    'admin.style_surface.become_supporter'                     => 'Become a supporter',

    /* ---------- tiers (includes/class-tiers.php) ---------- */
    'admin.tiers.streaming_library_access'                   => 'Streaming library access',
    'admin.tiers.downloadable_audio'                         => 'Downloadable audio',
    'admin.tiers.course_lms_access'                          => 'Course/LMS access',
    'admin.tiers.storefront_discount'                        => 'Storefront discount',
    'admin.tiers.tier'                                       => 'Tier',
    'admin.tiers.edit_tier'                                  => 'Edit Tier',
    'admin.tiers.woocommerce_isn_t_active_yet'               => 'WooCommerce isn\'t active yet — this tier will start selling automatically once it is. See <strong>The Self-Hosted Self → Monetization Settings</strong> to install it.',
    'admin.tiers.cover_image'                                => 'Cover image',
    'admin.tiers.change_image'                               => 'Change image',
    'admin.tiers.choose_image'                               => 'Choose image',
    'admin.tiers.remove'                                     => 'Remove',
    'admin.tiers.price_also_sets_tier_s'                     => 'This price also sets this tier\'s rank for access gating — a supporter tier "requires" here means this price or higher, so raising or lowering it can change who already qualifies.',
    'admin.tiers.monthly_price_usd'                          => 'Monthly price (USD)',
    'admin.tiers.annual_price_usd_optional'                  => 'Annual price (USD, optional)',
    'admin.tiers.leave_blank_offer_monthly_billing'          => '— leave blank to offer monthly billing only',
    'admin.tiers.requires_woocommerce_subscriptions_actua'   => 'Requires WooCommerce Subscriptions to actually bill annually — without it this stores the price but the tier still sells as the one-time fallback below.',
    'admin.tiers.free_trial_days_optional'                   => 'Free trial (days, optional)',
    'admin.tiers.0_no_trial'                                 => '— 0 = no trial',
    'admin.tiers.requires_woocommerce_subscriptions_actua_2' => 'Requires WooCommerce Subscriptions to actually delay the first charge — without it this stores the value but the one-time fallback purchase still charges immediately.',
    'admin.tiers.what_fans_see'                              => 'What fans see',
    'admin.tiers.marketing_copy_only_describes_tier'         => 'Marketing copy only — describes the tier, but grants nothing by itself.',
    'admin.tiers.benefits'                                   => 'Benefits',
    'admin.tiers.free_text_shown_tier_picker'                => '(free text — shown on the tier picker and on paywall notices)',
    'admin.tiers.benefits_list'                              => 'Benefits list',
    'admin.tiers.what_actually_gets_enforced'                => 'What actually gets enforced',
    'admin.tiers.real_recurring_billing_via_woocommerce'     => 'Real recurring billing via WooCommerce Subscriptions.',
    'admin.tiers.version_history'                            => 'Version History',
    'admin.tiers.version_no_longer_exists'                   => 'That version no longer exists.',
    'admin.tiers.restored_from_version'                      => 'Restored from version #',
    'admin.tiers.restored_version'                           => 'Restored version #',
];
