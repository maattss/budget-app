<?php

namespace App\Enums;

enum AssetType: string
{
    case BankAccount = 'bank_account';
    case Property = 'property';
    case Stock = 'stock';
    case Cash = 'cash';
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
            self::OtherAsset => false,
            self::Mortgage,
            self::Loan,
            self::CreditCard => true,
        };
    }
}
