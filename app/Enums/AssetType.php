<?php

namespace App\Enums;

enum AssetType: string
{
    case BankAccount = 'bank_account';
    case Property = 'property';
    case Stock = 'stock';
    case Cash = 'cash';
    case Car = 'car';
    case OtherAsset = 'other_asset';

    case Mortgage = 'mortgage';
    case Loan = 'loan';
    case CreditCard = 'credit_card';

    /**
     * The human-readable name for this type.
     */
    public function label(): string
    {
        return match ($this) {
            self::BankAccount => 'Bank account',
            self::Property => 'Property',
            self::Stock => 'Stocks & funds',
            self::Cash => 'Cash',
            self::Car => 'Car',
            self::OtherAsset => 'Other asset',
            self::Mortgage => 'Mortgage',
            self::Loan => 'Loan',
            self::CreditCard => 'Credit card',
        };
    }

    /**
     * Whether a value of this type counts against net worth rather than towards it.
     *
     * Every case is listed deliberately: a new type must make an explicit choice here
     * rather than defaulting to "asset" and quietly skewing net worth.
     */
    public function isLiability(): bool
    {
        return match ($this) {
            self::BankAccount,
            self::Property,
            self::Stock,
            self::Cash,
            self::Car,
            self::OtherAsset => false,
            self::Mortgage,
            self::Loan,
            self::CreditCard => true,
        };
    }

    /**
     * The icon name representing this type in lists and headings.
     *
     * Heroicons except for 'car', which Heroicons has no equivalent of and which is
     * therefore drawn locally in resources/views/flux/icon.
     */
    public function icon(): string
    {
        return match ($this) {
            self::BankAccount => 'building-library',
            self::Property => 'home-modern',
            self::Stock => 'arrow-trending-up',
            self::Cash => 'banknotes',
            self::Car => 'car',
            self::OtherAsset => 'archive-box',
            self::Mortgage => 'home',
            self::Loan => 'document-text',
            self::CreditCard => 'credit-card',
        };
    }

    /**
     * Tailwind utilities for this type's icon badge.
     *
     * Written out in full rather than interpolated, because Tailwind resolves class
     * names by scanning source text and would never see a constructed string.
     */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::BankAccount => 'bg-sky-50 text-sky-600 dark:bg-sky-950 dark:text-sky-400',
            self::Property => 'bg-amber-50 text-amber-600 dark:bg-amber-950 dark:text-amber-400',
            self::Stock => 'bg-violet-50 text-violet-600 dark:bg-violet-950 dark:text-violet-400',
            self::Cash => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950 dark:text-emerald-400',
            self::Car => 'bg-indigo-50 text-indigo-600 dark:bg-indigo-950 dark:text-indigo-400',
            self::OtherAsset => 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400',
            self::Mortgage => 'bg-rose-50 text-rose-600 dark:bg-rose-950 dark:text-rose-400',
            self::Loan => 'bg-orange-50 text-orange-600 dark:bg-orange-950 dark:text-orange-400',
            self::CreditCard => 'bg-pink-50 text-pink-600 dark:bg-pink-950 dark:text-pink-400',
        };
    }

    /**
     * This type's slot in the categorical chart palette, 1-8.
     *
     * Fixed per case rather than derived from position in a filtered list, so a type
     * keeps its colour when other types come and go. Colour follows the entity, never
     * its rank.
     *
     * Slots are unique *within* assets and within liabilities, but the two groups reuse
     * the same eight: Car and Mortgage both sit in slot 5. Nothing charts the two groups
     * together - the allocation bar is assets only, because a part-to-whole chart mixing
     * what you own with what you owe has no meaningful whole - so the collision is never
     * on screen. Adding a ninth colour that stays distinguishable in both themes is the
     * worse trade.
     */
    public function seriesSlot(): int
    {
        return match ($this) {
            self::BankAccount => 1,
            self::Property => 2,
            self::Stock => 3,
            self::Cash => 4,
            self::Car => 5,
            self::Mortgage => 5,
            self::Loan => 6,
            self::CreditCard => 7,
            self::OtherAsset => 8,
        };
    }

    /**
     * Every case that counts towards net worth rather than against it.
     *
     * @return array<int, self>
     */
    public static function assets(): array
    {
        return array_values(array_filter(self::cases(), fn (self $type): bool => ! $type->isLiability()));
    }

    /**
     * Every case that counts against net worth.
     *
     * @return array<int, self>
     */
    public static function liabilities(): array
    {
        return array_values(array_filter(self::cases(), fn (self $type): bool => $type->isLiability()));
    }
}
