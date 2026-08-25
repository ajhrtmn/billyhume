<?php

/**
 * The first real database-backed test for a money path in this
 * ecosystem — see this suite's bootstrap.php for why the pure-logic
 * tests/ tier (a hand-stubbed $wpdb) can't verify this class's actual
 * safety claim.
 *
 * BHM_Wallet::debit()'s own docblock claims the balance check and the
 * balance write are ONE atomic UPDATE (`... WHERE balance_cents >= %d`),
 * specifically to avoid a TOCTOU race where two concurrent debits for a
 * low-balance user could both pass a separate check before either write
 * commits. A stubbed $wpdb can only confirm the SQL string looks right;
 * only a real MySQL connection can confirm the WHERE clause actually
 * does what the comment claims.
 */
class WalletDebitTest extends WP_UnitTestCase {
    private int $user_id;

    public function set_up(): void {
        parent::set_up();
        $this->user_id = self::factory()->user->create();
        global $wpdb;
        $wpdb->insert(BHM_Tables::wallet(), [
            'user_id' => $this->user_id,
            'balance_cents' => 500,
        ]);
    }

    public function tear_down(): void {
        global $wpdb;
        $wpdb->delete(BHM_Tables::wallet(), ['user_id' => $this->user_id]);
        $wpdb->delete(BHM_Tables::wallet_ledger(), ['user_id' => $this->user_id]);
        parent::tear_down();
    }

    public function test_debit_with_sufficient_balance_succeeds_and_writes_the_ledger(): void {
        $ok = BHM_Wallet::debit($this->user_id, 200, null, 'play');
        $this->assertTrue($ok, 'A debit within the available balance must succeed.');
        $this->assertSame(300, BHM_Wallet::balance_cents($this->user_id), 'Balance must drop by exactly the debited amount.');

        global $wpdb;
        $ledger_table = BHM_Tables::wallet_ledger();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT delta_cents, reason FROM $ledger_table WHERE user_id = %d ORDER BY id DESC LIMIT 1",
            $this->user_id
        ), ARRAY_A);
        $this->assertNotNull($row, 'A successful debit must write a ledger row.');
        $this->assertSame(-200, (int) $row['delta_cents'], 'The ledger row must record the negative delta actually applied.');
        $this->assertSame('play', $row['reason']);
    }

    public function test_debit_exceeding_balance_is_declined_and_balance_is_unchanged(): void {
        $ok = BHM_Wallet::debit($this->user_id, 501, null, 'play');
        $this->assertFalse($ok, 'A debit exceeding the available balance must be declined.');
        $this->assertSame(500, BHM_Wallet::balance_cents($this->user_id), 'A declined debit must not touch the balance at all.');
    }

    public function test_debit_of_exactly_the_full_balance_succeeds(): void {
        $ok = BHM_Wallet::debit($this->user_id, 500, null, 'play');
        $this->assertTrue($ok, 'A debit for exactly the full balance is a valid boundary case, not an overdraft.');
        $this->assertSame(0, BHM_Wallet::balance_cents($this->user_id));
    }

    /**
     * The real claim this whole suite exists to check: fire more debit
     * attempts than the balance can cover, all against the SAME row, and
     * confirm the balance never goes negative — the exact race a
     * "check, then separately write" implementation would lose. A
     * single PHP process can't truly parallelize MySQL writes, but
     * looping the real debit() call repeatedly against a balance that
     * runs out partway through still exercises the SAME atomic UPDATE
     * path on every iteration — if the WHERE clause guard were ever
     * refactored away, this is the test that would catch a balance
     * going negative.
     */
    public function test_repeated_debits_never_drive_the_balance_negative(): void {
        $succeeded = 0;
        for ($i = 0; $i < 10; $i++) {
            if (BHM_Wallet::debit($this->user_id, 100, null, 'play')) {
                $succeeded++;
            }
        }
        // 500 cents / 100 per debit = exactly 5 can succeed.
        $this->assertSame(5, $succeeded, 'Exactly 5 of 10 attempted debits should succeed against a 500-cent balance.');
        $this->assertSame(0, BHM_Wallet::balance_cents($this->user_id), 'Balance must land at exactly 0, never negative.');
    }
}
