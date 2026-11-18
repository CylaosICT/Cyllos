<?php

namespace App\Entity;

use App\Payment\AmountOffer;
use App\Repository\StripeConfigRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A client's own Stripe account credentials (one per client). Payments go
 * straight to the client's Stripe account — Cylaos never holds the funds.
 *
 * Both secrets are stored encrypted at rest (see SecretEncryptor) and are
 * never displayed again once saved; only a non-sensitive hint of the API key
 * (mode + last characters) is kept in clear for the back office.
 */
#[ORM\Entity(repositoryClass: StripeConfigRepository::class)]
class StripeConfig
{
    public const SECRET_KEY_PATTERN = '/^(sk|rk)_(test|live)_[A-Za-z0-9]{10,}$/';
    public const WEBHOOK_SECRET_PATTERN = '/^whsec_[A-Za-z0-9]{10,}$/';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'stripeConfig', targetEntity: Client::class)]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    private Client $client;

    #[ORM\Column]
    private bool $active = true;

    /**
     * Secret or restricted API key (sk_… / rk_…), encrypted.
     */
    #[ORM\Column(type: 'text')]
    private string $secretKeyEncrypted = '';

    /**
     * Webhook signing secret (whsec_…), encrypted. Used to verify the
     * Stripe-Signature header of the events Stripe sends for this account.
     */
    #[ORM\Column(type: 'text')]
    private string $webhookSecretEncrypted = '';

    /**
     * Display-only hint of the API key, e.g. "rk_live_…a1b2". Safe to show.
     */
    #[ORM\Column(length: 40)]
    private string $secretKeyHint = '';

    /**
     * True for a live-mode key, false for a test-mode key.
     */
    #[ORM\Column]
    private bool $liveMode = false;

    #[ORM\Column]
    #[Assert\Positive]
    private int $minAmount = 5;

    #[ORM\Column]
    #[Assert\Positive]
    private int $maxAmount = 250;

    /** Payment page identity (cyllos-pay): optional hex colours (#RRGGBB). The logo is the client's own. */
    public const HEX_COLOR_PATTERN = '/^#[0-9A-Fa-f]{6}$/';

    #[ORM\Column(length: 7, nullable: true)]
    #[Assert\Regex(pattern: self::HEX_COLOR_PATTERN, message: 'Couleur invalide : attendu un code hexadécimal #RRGGBB.')]
    private ?string $brandPrimaryColor = null;

    #[ORM\Column(length: 7, nullable: true)]
    #[Assert\Regex(pattern: self::HEX_COLOR_PATTERN, message: 'Couleur invalide : attendu un code hexadécimal #RRGGBB.')]
    private ?string $brandBackgroundColor = null;

    #[ORM\Column(length: 7, nullable: true)]
    #[Assert\Regex(pattern: self::HEX_COLOR_PATTERN, message: 'Couleur invalide : attendu un code hexadécimal #RRGGBB.')]
    private ?string $brandSurfaceColor = null;

    #[ORM\Column(length: 7, nullable: true)]
    #[Assert\Regex(pattern: self::HEX_COLOR_PATTERN, message: 'Couleur invalide : attendu un code hexadécimal #RRGGBB.')]
    private ?string $brandTextColor = null;

    public function getBrandPrimaryColor(): ?string
    {
        return $this->brandPrimaryColor;
    }

    public function setBrandPrimaryColor(?string $color): static
    {
        $this->brandPrimaryColor = self::normalizeColor($color);

        return $this;
    }

    public function getBrandBackgroundColor(): ?string
    {
        return $this->brandBackgroundColor;
    }

    public function setBrandBackgroundColor(?string $color): static
    {
        $this->brandBackgroundColor = self::normalizeColor($color);

        return $this;
    }

    public function getBrandSurfaceColor(): ?string
    {
        return $this->brandSurfaceColor;
    }

    public function setBrandSurfaceColor(?string $color): static
    {
        $this->brandSurfaceColor = self::normalizeColor($color);

        return $this;
    }

    public function getBrandTextColor(): ?string
    {
        return $this->brandTextColor;
    }

    public function setBrandTextColor(?string $color): static
    {
        $this->brandTextColor = self::normalizeColor($color);

        return $this;
    }

    // --- Payment page: look, wording and flow (cyllos-pay). Everything is optional;
    // --- an empty value means "use the default". All of it is plain text, never HTML.

    /** Allowed type styles: key => label. The payment site maps each key to a system font stack. */
    public const PAY_FONTS = [
        'modern' => 'Moderne (sans-serif)',
        'classic' => 'Classique (serif)',
        'rounded' => 'Arrondie, conviviale',
        'mono' => 'Technique (monospace)',
    ];

    /** Allowed corner styles: key => label. */
    public const PAY_RADII = [
        'sharp' => 'Angles droits',
        'soft' => 'Légèrement arrondis',
        'round' => 'Très arrondis',
    ];

    /** Allowed page backgrounds: key => label. */
    public const PAY_BACKGROUNDS = [
        'aurora' => 'Dégradé animé',
        'shapes' => 'Formes flottantes',
        'plain' => 'Uni',
    ];

    public const PAY_MAX_QUICK_AMOUNTS = 6;

    #[ORM\Column(length: 10, nullable: true)]
    #[Assert\Choice(choices: ['modern', 'classic', 'rounded', 'mono'], message: 'Police inconnue.')]
    private ?string $payFont = null;

    #[ORM\Column(length: 10, nullable: true)]
    #[Assert\Choice(choices: ['sharp', 'soft', 'round'], message: 'Style de coins inconnu.')]
    private ?string $payRadius = null;

    #[ORM\Column(length: 10, nullable: true)]
    #[Assert\Choice(choices: ['aurora', 'shapes', 'plain'], message: 'Fond inconnu.')]
    private ?string $payBackground = null;

    #[ORM\Column(length: 80, nullable: true)]
    #[Assert\Length(max: 80)]
    private ?string $payTitle = null;

    #[ORM\Column(length: 300, nullable: true)]
    #[Assert\Length(max: 300)]
    private ?string $payIntro = null;

    #[ORM\Column(length: 40, nullable: true)]
    #[Assert\Length(max: 40)]
    private ?string $payButtonLabel = null;

    #[ORM\Column(length: 300, nullable: true)]
    #[Assert\Length(max: 300)]
    private ?string $paySuccessMessage = null;

    #[ORM\Column(length: 200, nullable: true)]
    #[Assert\Length(max: 200)]
    private ?string $paySupportText = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $payShowConfirmStep = true;

    /** Comma-separated whole euro amounts offered as one-tap choices, e.g. "10,20,50,100". */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $payQuickAmounts = null;

    // --- Amounts: how the payer picks what to pay, and what the Cyclos account receives.

    public const AMOUNT_MODE_RANGE = 'range';
    public const AMOUNT_MODE_LIST = 'list';
    public const AMOUNT_MODE_FIXED = 'fixed';

    /** Allowed amount modes: key => label. */
    public const AMOUNT_MODES = [
        self::AMOUNT_MODE_RANGE => 'Fourchette : la personne saisit le montant (entre un minimum et un maximum)',
        self::AMOUNT_MODE_LIST => 'Montants au choix : une liste de montants que vous définissez',
        self::AMOUNT_MODE_FIXED => 'Montant unique : un seul montant, non modifiable',
    ];

    public const MAX_AMOUNT_CHOICES = 12;

    /** One entry of the list: "100" (credit = payment) or "100:150" (pay 100, credited 150). Whole numbers. */
    private const CHOICE_PATTERN = '/^([1-9][0-9]{0,5})(?::([1-9][0-9]{0,6}))?$/';

    #[ORM\Column(length: 10, options: ['default' => 'range'])]
    #[Assert\Choice(choices: ['range', 'list', 'fixed'], message: 'Mode de montant inconnu.')]
    private string $payAmountMode = self::AMOUNT_MODE_RANGE;

    /** Comma-separated whole amounts for the "list" mode: "50,100:150,200". */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $payAmountChoices = null;

    /** "fixed" mode: what the payer pays, in whole euros. */
    #[ORM\Column(nullable: true)]
    #[Assert\Positive]
    #[Assert\LessThanOrEqual(999999)]
    private ?int $payFixedAmount = null;

    /** "fixed" mode: what the Cyclos account is credited with (empty = same as the payment). */
    #[ORM\Column(nullable: true)]
    #[Assert\Positive]
    #[Assert\LessThanOrEqual(9999999)]
    private ?int $payFixedCredit = null;

    /** "range" mode: units credited per euro paid (empty = 1). */
    #[ORM\Column(type: 'float', nullable: true)]
    #[Assert\Positive]
    #[Assert\LessThanOrEqual(10000)]
    private ?float $payCreditRate = null;

    /**
     * Name of the credited unit shown to payers, set by the client: "cigogne" or "cigogne|cigognes"
     * (singular|plural; a single word is used for both). Empty = "unités" (or "€" when credit = payment).
     */
    #[ORM\Column(length: 41, nullable: true)]
    #[Assert\Regex(pattern: '/^[^|]{1,20}(?:\|[^|]{1,20})?$/u', message: 'Un mot ou une expression de 20 caractères au plus, ou « singulier|pluriel » (ex. cigogne|cigognes).')]
    private ?string $payCreditUnit = null;

    public function getPayAmountMode(): string
    {
        return $this->payAmountMode;
    }

    public function setPayAmountMode(?string $mode): static
    {
        $this->payAmountMode = $mode === null || $mode === '' ? self::AMOUNT_MODE_RANGE : $mode;

        return $this;
    }

    public function getPayAmountChoices(): ?string
    {
        return $this->payAmountChoices;
    }

    public function setPayAmountChoices(?string $choices): static
    {
        // Normalised to "50,100:150": separators (comma, semicolon, space, new line) and empty entries dropped.
        $parts = preg_split('/[\s,;]+/', (string) $choices, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $this->payAmountChoices = $parts === [] ? null : implode(',', $parts);

        return $this;
    }

    public function getPayFixedAmount(): ?int
    {
        return $this->payFixedAmount;
    }

    public function setPayFixedAmount(?int $amount): static
    {
        $this->payFixedAmount = $amount;

        return $this;
    }

    public function getPayFixedCredit(): ?int
    {
        return $this->payFixedCredit;
    }

    public function setPayFixedCredit(?int $credit): static
    {
        $this->payFixedCredit = $credit;

        return $this;
    }

    public function getPayCreditRate(): ?float
    {
        return $this->payCreditRate;
    }

    public function setPayCreditRate(?float $rate): static
    {
        $this->payCreditRate = $rate;

        return $this;
    }

    public function getPayCreditUnit(): ?string
    {
        return $this->payCreditUnit;
    }

    /**
     * The unit's name for an amount: the client's own word (singular below 2 units,
     * plural otherwise), or "unités" when none was set.
     */
    public function creditUnitLabel(int $creditCents): string
    {
        if ($this->payCreditUnit === null) {
            return 'unités';
        }

        $words = explode('|', $this->payCreditUnit);

        return $creditCents < 200 ? $words[0] : ($words[1] ?? $words[0]);
    }

    public function setPayCreditUnit(?string $unit): static
    {
        $this->payCreditUnit = self::blankToNull($unit);

        return $this;
    }

    /**
     * The fixed offers of the "list" and "fixed" modes (empty in "range" mode,
     * where the payer types any amount).
     *
     * @return list<AmountOffer>
     */
    public function getAmountOffers(): array
    {
        if ($this->payAmountMode === self::AMOUNT_MODE_FIXED) {
            return $this->payFixedAmount === null
                ? []
                : [new AmountOffer($this->payFixedAmount * 100, ($this->payFixedCredit ?? $this->payFixedAmount) * 100)];
        }

        if ($this->payAmountMode !== self::AMOUNT_MODE_LIST || $this->payAmountChoices === null) {
            return [];
        }

        $offers = [];
        foreach (explode(',', $this->payAmountChoices) as $entry) {
            if (preg_match(self::CHOICE_PATTERN, $entry, $m) === 1) {
                $offers[] = new AmountOffer((int) $m[1] * 100, (int) ($m[2] ?? $m[1]) * 100);
            }
        }

        return $offers;
    }

    /**
     * What this configuration allows for the amount a payer asks to pay: the
     * matching offer (with the credit it brings), or null when not allowed.
     * The single rule used server-side when a payment starts.
     */
    public function offerFor(int $paidCents): ?AmountOffer
    {
        if ($this->payAmountMode === self::AMOUNT_MODE_RANGE) {
            if ($paidCents < $this->minAmount * 100 || $paidCents > $this->maxAmount * 100) {
                return null;
            }

            return new AmountOffer($paidCents, $this->payCreditRate === null ? $paidCents : (int) round($paidCents * $this->payCreditRate));
        }

        foreach ($this->getAmountOffers() as $offer) {
            if ($offer->paidCents === $paidCents) {
                return $offer;
            }
        }

        return null;
    }

    #[Assert\Callback]
    public function validateAmountMode(\Symfony\Component\Validator\Context\ExecutionContextInterface $context): void
    {
        if ($this->payAmountMode === self::AMOUNT_MODE_FIXED && $this->payFixedAmount === null) {
            $context->buildViolation('Indiquez le montant unique que la personne devra payer.')->atPath('payFixedAmount')->addViolation();
        }

        if ($this->payAmountMode !== self::AMOUNT_MODE_LIST) {
            return;
        }

        $problem = null;
        $paid = [];
        $entries = $this->payAmountChoices === null ? [] : explode(',', $this->payAmountChoices);
        if ($entries === []) {
            $problem = 'Indiquez au moins un montant.';
        } elseif (\count($entries) > self::MAX_AMOUNT_CHOICES) {
            $problem = \sprintf('Au plus %d montants.', self::MAX_AMOUNT_CHOICES);
        } else {
            foreach ($entries as $entry) {
                if (preg_match(self::CHOICE_PATTERN, $entry, $m) !== 1) {
                    $problem = 'Montants : des nombres entiers séparés par des virgules, avec « payé:crédité » si le crédit diffère (ex. 50, 100:150, 200).';
                    break;
                }
                if (\in_array($m[1], $paid, true)) {
                    $problem = 'Chaque montant payé ne doit apparaître qu\'une fois.';
                    break;
                }
                $paid[] = $m[1];
            }
        }
        if ($problem !== null) {
            $context->buildViolation($problem)->atPath('payAmountChoices')->addViolation();
        }
    }

    public function getPayFont(): ?string
    {
        return $this->payFont;
    }

    public function setPayFont(?string $payFont): static
    {
        $this->payFont = self::blankToNull($payFont);

        return $this;
    }

    public function getPayRadius(): ?string
    {
        return $this->payRadius;
    }

    public function setPayRadius(?string $payRadius): static
    {
        $this->payRadius = self::blankToNull($payRadius);

        return $this;
    }

    public function getPayBackground(): ?string
    {
        return $this->payBackground;
    }

    public function setPayBackground(?string $payBackground): static
    {
        $this->payBackground = self::blankToNull($payBackground);

        return $this;
    }

    public function getPayTitle(): ?string
    {
        return $this->payTitle;
    }

    public function setPayTitle(?string $payTitle): static
    {
        $this->payTitle = self::blankToNull($payTitle);

        return $this;
    }

    public function getPayIntro(): ?string
    {
        return $this->payIntro;
    }

    public function setPayIntro(?string $payIntro): static
    {
        $this->payIntro = self::blankToNull($payIntro);

        return $this;
    }

    public function getPayButtonLabel(): ?string
    {
        return $this->payButtonLabel;
    }

    public function setPayButtonLabel(?string $payButtonLabel): static
    {
        $this->payButtonLabel = self::blankToNull($payButtonLabel);

        return $this;
    }

    public function getPaySuccessMessage(): ?string
    {
        return $this->paySuccessMessage;
    }

    public function setPaySuccessMessage(?string $paySuccessMessage): static
    {
        $this->paySuccessMessage = self::blankToNull($paySuccessMessage);

        return $this;
    }

    public function getPaySupportText(): ?string
    {
        return $this->paySupportText;
    }

    public function setPaySupportText(?string $paySupportText): static
    {
        $this->paySupportText = self::blankToNull($paySupportText);

        return $this;
    }

    public function isPayShowConfirmStep(): bool
    {
        return $this->payShowConfirmStep;
    }

    public function setPayShowConfirmStep(bool $payShowConfirmStep): static
    {
        $this->payShowConfirmStep = $payShowConfirmStep;

        return $this;
    }

    public function getPayQuickAmounts(): ?string
    {
        return $this->payQuickAmounts;
    }

    public function setPayQuickAmounts(?string $payQuickAmounts): static
    {
        // Normalised to "10,20,50": spaces and empty entries dropped.
        $parts = array_filter(array_map('trim', explode(',', (string) $payQuickAmounts)), static fn (string $p): bool => $p !== '');
        $this->payQuickAmounts = $parts === [] ? null : implode(',', $parts);

        return $this;
    }

    /**
     * @return list<int> the quick amounts, in euros, as configured
     */
    public function getPayQuickAmountList(): array
    {
        return $this->payQuickAmounts === null ? [] : array_map('intval', explode(',', $this->payQuickAmounts));
    }

    #[Assert\Callback]
    public function validateQuickAmounts(\Symfony\Component\Validator\Context\ExecutionContextInterface $context): void
    {
        // Quick amounts only exist in "range" mode.
        if ($this->payQuickAmounts === null || $this->payAmountMode !== self::AMOUNT_MODE_RANGE) {
            return;
        }

        $parts = explode(',', $this->payQuickAmounts);
        $problem = null;
        if (\count($parts) > self::PAY_MAX_QUICK_AMOUNTS) {
            $problem = \sprintf('Au plus %d montants rapides.', self::PAY_MAX_QUICK_AMOUNTS);
        } elseif (\count($parts) !== \count(array_unique($parts))) {
            $problem = 'Chaque montant ne doit apparaître qu\'une fois.';
        } else {
            foreach ($parts as $part) {
                if (preg_match('/^[1-9][0-9]{0,5}$/', $part) !== 1) {
                    $problem = 'Montants rapides : des nombres entiers d\'euros séparés par des virgules (ex. 10, 20, 50).';
                    break;
                }
                if ((int) $part < $this->minAmount || (int) $part > $this->maxAmount) {
                    $problem = \sprintf('Chaque montant rapide doit être compris entre %d € et %d € (bornes du client).', $this->minAmount, $this->maxAmount);
                    break;
                }
            }
        }

        if ($problem !== null) {
            $context->buildViolation($problem)->atPath('payQuickAmounts')->addViolation();
        }
    }

    private static function blankToNull(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }

    private static function normalizeColor(?string $color): ?string
    {
        $color = $color === null ? null : trim($color);
        if ($color === null || $color === '') {
            return null;
        }
        if (preg_match('/^[0-9A-Fa-f]{6}$/', $color) === 1) {
            $color = '#' . $color;
        }

        return strtolower($color);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getClient(): ?Client
    {
        // Not initialised until the config is attached to a client.
        return $this->client ?? null;
    }

    public function setClient(Client $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getSecretKeyEncrypted(): string
    {
        return $this->secretKeyEncrypted;
    }

    public function setSecretKeyEncrypted(string $secretKeyEncrypted): static
    {
        $this->secretKeyEncrypted = $secretKeyEncrypted;

        return $this;
    }

    public function getWebhookSecretEncrypted(): string
    {
        return $this->webhookSecretEncrypted;
    }

    public function setWebhookSecretEncrypted(string $webhookSecretEncrypted): static
    {
        $this->webhookSecretEncrypted = $webhookSecretEncrypted;

        return $this;
    }

    public function getSecretKeyHint(): string
    {
        return $this->secretKeyHint;
    }

    public function isLiveMode(): bool
    {
        return $this->liveMode;
    }

    /**
     * Records the non-sensitive hint (and live/test mode) derived from the
     * plaintext key, so the back office can tell which key is configured
     * without ever being able to read it back.
     */
    public function rememberSecretKeyHint(#[\SensitiveParameter] string $plainSecretKey): static
    {
        $prefix = preg_match('/^(?:sk|rk)_(?:test|live)_/', $plainSecretKey, $matches) === 1 ? $matches[0] : '';
        $this->liveMode = str_ends_with($prefix, '_live_');
        $this->secretKeyHint = $prefix . '…' . substr($plainSecretKey, -4);

        return $this;
    }

    public function getMinAmount(): int
    {
        return $this->minAmount;
    }

    public function setMinAmount(int $minAmount): static
    {
        $this->minAmount = $minAmount;

        return $this;
    }

    public function getMaxAmount(): int
    {
        return $this->maxAmount;
    }

    public function setMaxAmount(int $maxAmount): static
    {
        $this->maxAmount = $maxAmount;

        return $this;
    }

    #[Assert\Callback]
    public function validateAmountRange(\Symfony\Component\Validator\Context\ExecutionContextInterface $context): void
    {
        if ($this->payAmountMode === self::AMOUNT_MODE_RANGE && $this->minAmount > $this->maxAmount) {
            $context->buildViolation('Le montant minimum ne peut pas dépasser le montant maximum.')
                ->atPath('minAmount')
                ->addViolation();
        }
    }
}
