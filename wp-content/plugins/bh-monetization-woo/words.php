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
];
