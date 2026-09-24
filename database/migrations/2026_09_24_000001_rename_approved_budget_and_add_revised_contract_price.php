<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rename approved_budget -> contract_price and add revised_contract_price.
 *
 * WHY THE RENAME. Every user-facing label in the app already reads "Contract Price";
 * only the column still said approved_budget, which also reads as ABC ("Approved
 * Budget for the Contract") and invited exactly that confusion. This is the schema
 * catching up with the UI, not a change of meaning: the value is, and always was,
 * the AWARDED amount after any voluntary contractor discount.
 *
 * WHY THE NEW COLUMN. A variation / change order can revise a contract after award
 * (GPPB allows up to 10% of the original contract price). The system had nowhere to
 * put that figure, so a revision could only be recorded by overwriting the original
 * and losing it.
 *
 * ⚠ revised_contract_price IS NULLABLE AND IS NEVER MIRRORED FROM contract_price.
 * If every project carried a revised value, "never revised" and "revised to the same
 * number" would be indistinguishable — destroying the one fact the column exists to
 * record. Read it through Project::effectiveContractPrice() instead.
 *
 * ⚠ THIS IS THE FIRST SCHEMA CHANGE SINCE PILOT VERSION 1, and it is a RENAME, so
 * rolling a pilot machine back to v1 is NO LONGER a plain file copy: v1 code selects
 * approved_budget. Run down() FIRST, then restore the v1 files. See
 * textfiles/contract_price_and_revised_amount_plan.md §4.
 *
 * Nullability is carried over unchanged (the column was created nullable) so the
 * rename cannot fail on existing rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Separate statements: some drivers do not accept a rename and an add in one
        // ALTER, and SQLite (the test database) rewrites the table per operation.
        Schema::table('projects', function (Blueprint $table) {
            $table->renameColumn('approved_budget', 'contract_price');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->decimal('revised_contract_price', 15, 2)
                ->nullable()
                ->after('contract_price');
        });
    }

    public function down(): void
    {
        // ⚠ THIS DISCARDS EVERY REVISED AMOUNT RECORDED SINCE THE UPGRADE. There is
        // nowhere in the v1 schema to keep them. Export them before rolling back if
        // they matter.
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('revised_contract_price');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->renameColumn('contract_price', 'approved_budget');
        });
    }
};
