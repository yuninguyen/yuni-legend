<?php

namespace App\Filament\Resources\Traits;

use App\Filament\Resources\AccountResource;
use App\Models\Account;
use App\Models\Platform;
use App\Models\RebateTracker;
use App\Models\User;
use App\Services\GoogleSyncService;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\Tabs;
use Filament\Infolists\Components\Tabs\Tab;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Alignment;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Js;

trait HasAccountSchema
{
    use HasPlatform;
    use HasPlatformCache;
    use HasUsStates;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('system.account_claim.section_title'))
                    ->schema([
                        Select::make('platform')
                            ->label(__('system.labels.platform'))
                            ->placeholder(__('system.placeholders.search_platform'))
                            ->options(self::getPlatforms())
                            ->required()
                            ->disabled(fn () => ! auth()->user()?->isAdmin())
                            ->native(false),

                        Select::make('email_id')
                            ->label(__('system.labels.email_address'))
                            ->placeholder(__('system.placeholders.select_email'))
                            ->relationship('email', 'email')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->disabled(fn () => ! auth()->user()?->isAdmin())
                            ->createOptionForm([
                                Forms\Components\TextInput::make('email')->email()->required(),
                                Forms\Components\TextInput::make('email_password')
                                    ->label(__('system.labels.password'))
                                    ->required(),
                                Forms\Components\TextInput::make('recovery_email')
                                    ->label(__('system.labels.recovery_email'))
                                    ->email(),
                                Forms\Components\TextInput::make('two_factor_code')
                                    ->label(__('system.labels.two_factor_code'))
                                    ->placeholder(__('system.placeholders.enter_2fa')),
                                Select::make('status')
                                    ->label(__('system.labels.status'))
                                    ->options([
                                        'active' => __('system.status.live'),
                                        'disabled' => __('system.status.disabled'),
                                        'locked' => __('system.status.locked'),
                                    ])
                                    ->default('active')
                                    ->required()
                                    ->native(false),

                                Forms\Components\Textarea::make('email_note')
                                    ->label(__('system.labels.note'))
                                    ->placeholder(__('system.placeholders.email_note_helper')),
                            ]),

                        Forms\Components\TextInput::make('password')
                            ->label(__('system.labels.password'))
                            ->password()
                            ->revealable()
                            ->required()
                            ->disabled(fn () => ! auth()->user()?->isAdmin()),

                        Select::make('state')
                            ->label(__('system.labels.state_us'))
                            ->placeholder(__('system.placeholders.select_state'))
                            ->options(self::$usStates)
                            ->disabled(fn () => ! auth()->user()?->isAdmin()),

                        Forms\Components\TextInput::make('device')
                            ->label(__('system.labels.device_create'))
                            ->placeholder(__('system.placeholders.device_placeholder'))
                            ->disabled(fn () => ! auth()->user()?->isAdmin()),

                        Forms\Components\TextInput::make('account_created_at')
                            ->label(__('system.labels.date_create'))
                            ->placeholder(__('system.placeholders.date_format'))
                            ->nullable()
                            ->default(null)
                            ->mask('99/99/9999')
                            ->rules(['date_format:d/m/Y'])
                            ->disabled(fn () => ! auth()->user()?->isAdmin())
                            ->dehydrateStateUsing(function ($state) {
                                if (blank($state)) {
                                    return null;
                                }
                                try {
                                    return Carbon::createFromFormat('d/m/Y', $state)->format('Y-m-d');
                                } catch (\Exception $e) {
                                    return null;
                                }
                            }),

                        Forms\Components\TextInput::make('paypal_info')
                            ->label(__('system.labels.paypal_address'))
                            ->placeholder(__('system.placeholders.paypal_info_placeholder'))
                            ->disabled(fn () => ! auth()->user()?->isAdmin()),

                        Forms\Components\TextInput::make('device_linked_paypal')
                            ->label(__('system.labels.device_linked_paypal'))
                            ->placeholder(__('system.placeholders.device_placeholder'))
                            ->disabled(fn () => ! auth()->user()?->isAdmin()),

                        Forms\Components\TextInput::make('paypal_linked_at')
                            ->label(__('system.labels.linked_paypal_date'))
                            ->placeholder(__('system.placeholders.date_format'))
                            ->nullable()
                            ->default(null)
                            ->mask('99/99/9999')
                            ->rules(['date_format:d/m/Y'])
                            ->disabled(fn () => ! auth()->user()?->isAdmin())
                            ->dehydrateStateUsing(function ($state) {
                                if (blank($state)) {
                                    return null;
                                }
                                try {
                                    return Carbon::createFromFormat('d/m/Y', $state)->format('Y-m-d');
                                } catch (\Exception $e) {
                                    return null;
                                }
                            }),

                        Select::make('user_id')
                            ->relationship('user', 'name')
                            ->label(__('system.labels.holder'))
                            ->searchable()
                            ->preload()
                            ->disabled(fn () => ! auth()->user()?->isAdmin()),

                        Select::make('status')
                            ->label(__('system.labels.status'))
                            ->multiple()
                            ->options([
                                'active' => __('system.status.active'),
                                'used' => __('system.status.used'),
                                'banned' => __('system.status.banned'),
                                'no_paypal_needed' => __('system.status.no_paypal_required'),
                                'not_linked' => __('system.status.not_linked_paypal'),
                                'limited' => __('system.status.paypal_limited'),
                                'linked' => __('system.status.linked_paypal'),
                                'unlinked' => __('system.status.unlinked_paypal'),
                            ])
                            ->searchable()
                            ->preload()
                            ->disabled(fn () => ! auth()->user()?->isAdmin())
                            ->native(false),

                        Forms\Components\Textarea::make('note')
                            ->label(__('system.labels.note'))
                            ->placeholder(__('system.placeholders.account_note_helper')),
                    ])
                    ->columns(2)
                    ->extraAttributes([
                        'style' => 'overflow: visible !important; z-index: 50 !important; position: relative;',
                        'class' => '!overflow-visible !z-50',
                    ]),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Tabs::make('Tabs')
                    ->tabs([
                        Tab::make(__('system.heading_infolist.email_information'))
                            ->icon('heroicon-m-envelope')
                            ->schema([
                                Grid::make(2)->schema([
                                    TextEntry::make('email.email')
                                        ->label(__('system.labels.email_address'))
                                        ->copyable()
                                        ->placeholder(__('system.n/a')),
                                    TextEntry::make('email.email_password')
                                        ->label(__('system.labels.password'))
                                        ->copyable()
                                        ->placeholder(__('system.n/a')),
                                    TextEntry::make('email.recovery_email')
                                        ->label(__('system.labels.account_email'))
                                        ->copyable()
                                        ->placeholder(__('system.n/a')),
                                    TextEntry::make('email.two_factor_code')
                                        ->label(__('system.labels.two_factor_code'))
                                        ->copyable()
                                        ->placeholder(__('system.n/a')),
                                    TextEntry::make('email.status')
                                        ->label(__('system.labels.status'))
                                        ->placeholder(__('system.n/a'))
                                        ->formatStateUsing(fn (string $state): string => match ($state) {
                                            'active', 'Live' => __('system.email_status.active'),
                                            'disabled', 'Disabled' => __('system.email_status.disabled'),
                                            'locked', 'Locked' => __('system.email_status.locked'),
                                            default => __('system.email_status.'.$state),
                                        })
                                        ->color(fn (string $state): string => match ($state) {
                                            'active', 'Live' => 'success',
                                            'disabled', 'Disabled' => 'warning',
                                            'locked', 'Locked' => 'danger',
                                            default => 'gray',
                                        }),
                                ]),
                                TextEntry::make('email.note')
                                    ->label(__('system.labels.note'))
                                    ->placeholder(__('system.n/a'))
                                    ->columnSpanFull(),
                            ]),

                        Tab::make(__('system.heading_infolist.account_information'))
                            ->icon('heroicon-m-identification')
                            ->schema([
                                Grid::make(3)->schema([
                                    TextEntry::make('platform')
                                        ->label(__('system.labels.platform'))
                                        ->placeholder(__('system.n/a'))
                                        ->formatStateUsing(fn ($state) => $state ? static::getPlatformName($state) : 'N/A'),
                                    TextEntry::make('password')
                                        ->label(__('system.labels.password'))
                                        ->copyable()
                                        ->placeholder(__('system.n/a')),
                                    TextEntry::make('state')
                                        ->label(__('system.labels.state_us'))
                                        ->placeholder(__('system.n/a'))
                                        ->formatStateUsing(fn ($state) => $state ? "{$state} - ".(self::$usStates[$state] ?? '') : 'N/A'),
                                    TextEntry::make('device')
                                        ->label(__('system.labels.device'))
                                        ->placeholder(__('system.n/a')),
                                    TextEntry::make('account_created_at')
                                        ->label(__('system.labels.date_create'))
                                        ->dateTime('d/m/Y')
                                        ->placeholder(__('system.n/a')),
                                    TextEntry::make('user.name')
                                        ->label(__('system.labels.holder'))
                                        ->placeholder(__('system.n/a')),
                                ]),
                                TextEntry::make('status')
                                    ->label(__('system.labels.status'))
                                    ->html()
                                    ->placeholder(__('system.n/a'))
                                    ->columnSpanFull()
                                    ->formatStateUsing(function ($state): ?string {
                                        if (blank($state)) {
                                            return null;
                                        }

                                        $statuses = is_array($state)
                                            ? $state
                                            : array_map('trim', explode(',', (string) $state));

                                        $html = collect($statuses)->filter()->map(function ($s) {
                                            $s_lower = strtolower($s);
                                            $label = match ($s_lower) {
                                                'used', 'in_use' => __('system.status.used'),
                                                'limited', 'paypal_limited' => __('system.status.paypal_limited'),
                                                'linked', 'linked_paypal' => __('system.status.linked_paypal'),
                                                'unlinked', 'unlinked_paypal' => __('system.status.unlinked_paypal'),
                                                'not_linked', 'not_linked_paypal' => __('system.status.not_linked_paypal'),
                                                'no_paypal_needed', 'no_paypal_required' => __('system.status.no_paypal_required'),
                                                'banned' => __('system.status.banned'),
                                                'active', 'live' => __('system.status.active'),
                                                default => __('system.status.'.$s_lower),
                                            };

                                            $color = match ($s_lower) {
                                                'active', 'live' => '#6b7280',
                                                'used', 'in_use' => '#3b82f6',
                                                'no_paypal_needed', 'no_paypal_required' => '#f59e0b',
                                                'not_linked', 'not_linked_paypal' => '#f59e0b',
                                                'linked', 'linked_paypal' => '#22c55e',
                                                'limited', 'paypal_limited' => '#ef4444',
                                                'unlinked', 'unlinked_paypal' => '#f59e0b',
                                                'banned' => '#ef4444',
                                                default => '#6b7280',
                                            };

                                            return "<span style='color: {$color}; font-weight: 600; padding: 2px 8px; border-radius: 4px;'>{$label}</span>";
                                        })->implode("<span style='margin: 0 6px; color: #94a3b8; font-weight: bold;'>→</span>");

                                        return $html;
                                    }),
                                TextEntry::make('note')
                                    ->label(__('system.labels.note'))
                                    ->placeholder(__('system.n/a'))
                                    ->columnSpanFull()
                                    ->html()
                                    ->formatStateUsing(fn ($state) => $state ? '
                                        <div style="
                                            white-space: pre-wrap;
                                            line-height: 1.6;
                                            margin: 0;
                                            padding: 0;
                                        ">'.e(trim($state)).'</div>' : 'N/A'),
                            ]),

                        Tab::make(__('system.heading_infolist.paypal_information'))
                            ->icon('heroicon-m-currency-dollar')
                            ->schema([
                                Grid::make(2)->schema([
                                    TextEntry::make('device_linked_paypal')
                                        ->label(__('system.labels.device'))
                                        ->placeholder(__('system.n/a')),
                                    TextEntry::make('paypal_linked_at')
                                        ->label(__('system.labels.linked_paypal_date'))
                                        ->dateTime('d/m/Y')
                                        ->placeholder(__('system.n/a')),
                                    TextEntry::make('paypal_info')
                                        ->label(__('system.labels.address'))
                                        ->placeholder(__('system.n/a'))
                                        ->columnSpanFull(),
                                ]),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['email', 'user'])) // 🟢 TỐI ƯU: Eager Load để tránh N+1
            ->recordUrl(null)
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->alignment(Alignment::Center)
                    ->searchable()
                    ->toggleable()
                    ->extraHeaderAttributes(['style' => 'width: 60px; min-width: 60px'])
                    ->extraAttributes(['style' => 'width: 60px; min-width: 60px'])
                    ->color('gray'),

                TextColumn::make('platform')
                    ->label(__('system.labels.platform'))
                    ->searchable()
                    ->alignment(Alignment::Center)
                    // ->extraHeaderAttributes(['style' => 'width: 80px; min-width: 80px'])
                    // ->extraAttributes(['style' => 'width: 80px; min-width: 80px'])
                    ->visible(static::class === AccountResource::class)
                    ->formatStateUsing(fn ($state) => $state ? static::getPlatformName($state) : 'N/A'),

                TextColumn::make('email.email')
                    ->label(__('system.labels.email_address'))
                    ->alignment(Alignment::Center)
                    ->extraHeaderAttributes(['style' => 'width: 280px; min-width: 280px'])
                    ->extraAttributes(['style' => 'width: 280px; min-width: 280px'])
                    ->wrap()
                    ->searchable()
                    ->action(fn () => null)
                    ->html()
                    ->formatStateUsing(function (Account $record): string {
                        $email = $record->email?->email ?? __('system.n/a');
                        $platformPass = $record->password ?? __('system.n/a');
                        $pass = $record->email?->email_password ?? __('system.n/a');
                        $rec = $record->email?->recovery_email ?? __('system.n/a');
                        $twoFA = $record->email?->two_factor_code ?? __('system.n/a');
                        $emailNote = $record->email?->note ?? __('system.n/a');
                        $emailStatus = $record->email?->status ?? '';
                        [$emailstatusLabels, $emailstatusLabelsColor] = match (strtolower($emailStatus)) {
                            'active', 'live' => [__('system.email_status.active'), '#22c55e'],
                            'disabled' => [__('system.email_status.disabled'), '#f59e0b'],
                            'locked' => [__('system.email_status.locked'), '#ef4444'],
                            default => [
                                (blank($emailStatus) || strtolower($emailStatus) === 'n/a') ? 'N/A' : __('system.email_status.'.$emailStatus),
                                '#6b7280',
                            ],
                        };

                        return '
                            <div x-data="{ copied: null }" style="text-align: left; line-height: 1.6; padding-left: 0;">
                                <div style="margin-bottom: 2px;">
                                    <span style="color: #1e293b; font-weight: 700; cursor: pointer; position: relative;" 
                                          x-on:click.stop.prevent="window.navigator.clipboard.writeText('.e(Js::from($email))."); copied = 'email'; setTimeout(() => copied = null, 5000)\"
                                          onclick=\"event.stopPropagation();\">
                                        {$email}
                                        <span x-show=\"copied === 'email'\" x-cloak style=\"display: none; position: absolute; left: 100%; top: 0; color: #059669; font-weight: 700; font-size: 11px; margin-left: 8px; white-space: nowrap;\">✓ ".__('system.labels.copied').'</span>
                                    </span>
                                </div>

                                <div style="margin-bottom: 2px;">
                                    <span style="color: #64748b;">'.__('system.labels.password_account').': </span> 
                                    <span style="color: #1e293b; cursor: pointer; position: relative;" 
                                          x-on:click.stop.prevent="window.navigator.clipboard.writeText('.e(Js::from($platformPass))."); copied = 'platform'; setTimeout(() => copied = null, 5000)\"
                                          onclick=\"event.stopPropagation();\">
                                        {$platformPass}
                                        <span x-show=\"copied === 'platform'\" x-cloak style=\"display: none; position: absolute; left: 100%; top: 0; color: #059669; font-weight: 700; font-size: 11px; margin-left: 8px; white-space: nowrap;\">✓ ".__('system.labels.copied').'</span>
                                    </span>
                                </div>
                                
                                <!-- <div style="margin-bottom: 2px;">
                                    <span style="color: #64748b;">'.__('system.labels.password_email').': </span> 
                                    <span style="color: #1e293b; cursor: pointer; position: relative;" 
                                          x-on:click.stop.prevent="window.navigator.clipboard.writeText('.e(Js::from($pass))."); copied = 'pass'; setTimeout(() => copied = null, 5000)\"
                                          onclick=\"event.stopPropagation();\">
                                        {$pass}
                                        <span x-show=\"copied === 'pass'\" x-cloak style=\"display: none; position: absolute; left: 100%; top: 0; color: #059669; font-weight: 700; font-size: 11px; margin-left: 8px; white-space: nowrap;\">✓ ".__('system.labels.copied').'</span>
                                    </span>
                                </div>

                                <div style="margin-bottom: 2px; overflow: hidden; text-overflow: ellipsis; white-space: wrap;">
                                    <span style="color: #64748b;">'.__('system.labels.recovery_email').': </span> 
                                    <span style="color: #1e293b; cursor: pointer; position: relative;" 
                                          x-on:click.stop.prevent="window.navigator.clipboard.writeText('.e(Js::from($rec))."); copied = 'rec'; setTimeout(() => copied = null, 5000)\"
                                          onclick=\"event.stopPropagation();\">
                                        {$rec}
                                        <span x-show=\"copied === 'rec'\" x-cloak style=\"display: none; position: absolute; left: 100%; top: 0; color: #059669; font-weight: 700; font-size: 11px; margin-left: 8px; white-space: nowrap;\">✓ ".__('system.labels.copied').'</span>
                                    </span>
                                </div>

                                <div style="margin-bottom: 2px;">
                                    <span style="color: #64748b;">'.__('system.labels.two_factor_code').': </span> 
                                    <span style="color: #1e293b; cursor: pointer; position: relative;" 
                                          x-on:click.stop.prevent="window.navigator.clipboard.writeText('.e(Js::from($twoFA))."); copied = 'twoFA'; setTimeout(() => copied = null, 5000)\"
                                          onclick=\"event.stopPropagation();\">
                                        {$twoFA}
                                        <span x-show=\"copied === 'twoFA'\" x-cloak style=\"display: none; position: absolute; left: 100%; top: 0; color: #059669; font-weight: 700; font-size: 11px; margin-left: 8px; white-space: nowrap;\">✓ ".__('system.labels.copied').'</span>
                                    </span>
                                </div>
                                    
                                <div style="margin-top: 8px; padding-top: 4px; border-top: 1px solid #f1f5f9; line-height: 1.8;">
                                    <div style="margin-top: 2px; overflow: hidden; text-overflow: ellipsis; white-space: wrap;">
                                        <span style="color: #64748b;">'.__('system.labels.status').": </span> 
                                        <span style=\"color: {$emailstatusLabelsColor};\">{$emailstatusLabels}</span>
                                    </div>

                                    <div style=\"margin-bottom: 2px;\">
                                        <span style=\"color: #64748b;\">".__('system.labels.note').": </span> 
                                        <span style=\"color: #1e293b;\">{$emailNote}</span>
                                    </div>
                                </div> -->
                            </div>
                        ";
                    }),

                // SOURCE INFORMATION: removed wide column, now shown via 'View Detail' action button below

                TextColumn::make('status')
                    ->label(__('system.labels.status'))
                    ->alignment(Alignment::Center)
                    ->html()
                    ->formatStateUsing(function ($state): ?string {
                        if (blank($state)) {
                            return null;
                        }

                        // Tách chuỗi trạng thái nếu cần
                        $statuses = is_array($state)
                            ? $state
                            : array_map('trim', explode(',', (string) $state));

                        $html = collect($statuses)->filter()->map(function ($s) {
                            $s_lower = strtolower($s);
                            $label = match ($s_lower) {
                                'used', 'in_use' => __('system.status.used'),
                                'limited', 'paypal_limited' => __('system.status.paypal_limited'),
                                'linked', 'linked_paypal' => __('system.status.linked_paypal'),
                                'unlinked', 'unlinked_paypal' => __('system.status.unlinked_paypal'),
                                'not_linked', 'not_linked_paypal' => __('system.status.not_linked_paypal'),
                                'no_paypal_needed', 'no_paypal_required' => __('system.status.no_paypal_required'),
                                'banned' => __('system.status.banned'),
                                'active', 'live' => __('system.status.active'),
                                default => __('system.status.'.$s_lower),
                            };

                            $color = match ($s_lower) {
                                'active', 'live' => '#6b7280', // Gray
                                'used', 'in_use' => '#3b82f6',   // Info Blue
                                'no_paypal_needed', 'no_paypal_required' => '#f59e0b', // Warning Orange
                                'not_linked', 'not_linked_paypal' => '#f59e0b',
                                'linked', 'linked_paypal' => '#22c55e',   // Success Green
                                'limited', 'paypal_limited' => '#ef4444',  // Danger Red
                                'unlinked', 'unlinked_paypal' => '#f59e0b',
                                'banned' => '#ef4444',
                                default => '#6b7280',
                            };

                            return "<span style='color: {$color}; font-weight: 600; padding: 2px 6px; border-radius: 4px;'>{$label}</span>";
                        })->implode("<span style='margin: 0 4px; color: #94a3b8; font-weight: bold;'>→</span>");

                        return $html;
                    })
                    ->tooltip(function (TextColumn $column, Account $record): string {
                        $statuses = is_array($record->status) ? $record->status : [$record->status];

                        return collect($statuses)
                            ->map(fn ($s) => match ($s) {
                                'active' => __('system.accounts.status_explanations.active'),
                                'used' => __('system.accounts.status_explanations.used'),
                                'limited' => __('system.accounts.status_explanations.limited'),
                                'banned' => __('system.accounts.status_explanations.banned'),
                                'linked' => __('system.accounts.status_explanations.linked'),
                                'unlinked' => __('system.accounts.status_explanations.unlinked'),
                                'not_linked' => __('system.accounts.status_explanations.not_linked'),
                                'no_paypal_needed' => __('system.accounts.status_explanations.no_paypal_needed'),
                                default => __('system.accounts.status_explanations.default'),
                            })
                            ->join("\n");
                    }),

                TextColumn::make('user.name')
                    ->label(__('system.labels.holder'))
                    ->alignment(Alignment::Center)
                    ->default(__('system.unassigned'))
                    ->color(fn (Account $record) => $record->user_id === null ? 'gray' : 'default')
                    ->html()
                    ->description(function (Account $record): ?HtmlString {
                        $user = auth()->user();
                        if ($record->user_id === null && ! $user?->isFinance()) {
                            return new HtmlString(
                                '<span class = "get-account-btn">'.__('system.get_account').'</span>'
                            );
                        }

                        return null;
                    })
                    ->extraAttributes(function (Account $record) {
                        $user = auth()->user();
                        $styles = 'font-weight: 400 !important; line-height: 1.2;';
                        if ($record->user_id === null && ! $user?->isFinance()) {
                            return [
                                'class' => 'cursor-pointer transition hover:opacity-70',
                                'wire:click.stop' => "mountTableAction('get_account', '{$record->id}')",
                            ];
                        }

                        return ['style' => $styles];
                    }),
            ])
            ->persistFiltersInSession()
            ->filters([
                SelectFilter::make('email_status')
                    ->label(__('system.labels.email_status'))
                    ->options([
                        'active' => __('system.status.live'),
                        'disabled' => __('system.status.disabled'),
                        'locked' => __('system.status.locked'),
                    ])
                    ->query(fn ($query, $data) => $query->when(
                        $data['value'],
                        fn ($q, $value) => $q->whereHas('email', fn ($q) => $q->where('status', $value))
                    )),

                SelectFilter::make('platform')
                    ->label(__('system.labels.platform'))
                    ->multiple()
                    ->options(function () {
                        $platforms = Account::query()
                            ->distinct()
                            ->whereNotNull('platform')
                            ->pluck('platform', 'platform')
                            ->map(fn ($label) => (string) $label)
                            ->toArray();
                        $platforms_map = Platform::pluck('name', 'slug')->toArray();
                        $formattedOptions = [];
                        foreach ($platforms as $p) {
                            $formattedOptions[$p] = $platforms_map[$p] ?? $p;
                        }

                        return $formattedOptions;
                    })
                    ->searchable(),

                Filter::make('year_created')
                    ->form([
                        Select::make('year')
                            ->label(__('system.labels.year_created'))
                            ->multiple()
                            ->options(function () {
                                return Account::query()
                                    ->selectRaw(match (DB::getDriverName()) {
                                        'sqlite' => "strftime('%Y', account_created_at) as year",
                                        'pgsql' => "to_char(account_created_at, 'YYYY') as year",
                                        default => 'YEAR(account_created_at) as year',
                                    })
                                    ->whereNotNull('account_created_at')
                                    ->distinct()
                                    ->orderBy('year', 'desc')
                                    ->pluck('year', 'year')
                                    ->toArray();
                            }),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            $data['year'],
                            fn (Builder $query, $year): Builder => $query->whereYear('account_created_at', $year),
                        );
                    }),

                Tables\Filters\TernaryFilter::make('my_accounts')
                    ->label(__('system.account_claim.title'))
                    ->trueLabel(__('system.labels.my_accounts'))
                    ->falseLabel(__('system.unassigned'))
                    ->queries(
                        true: fn (Builder $query) => $query->where('user_id', auth()->id()),
                        false: fn (Builder $query) => $query->whereNull('user_id'),
                        blank: fn (Builder $query) => $query,
                    ),

                SelectFilter::make('user_id')
                    ->label(__('system.labels.holder'))
                    ->visible(fn () => auth()->user()?->isAdmin())
                    ->options(function () {
                        return User::query()
                            ->whereNotNull('name')
                            ->where('name', '!=', '')
                            ->pluck('name', 'id')
                            ->map(fn ($name) => (string) $name)
                            ->prepend(__('system.unassigned'), 'unassigned')
                            ->toArray();
                    })
                    ->query(function (Builder $query, array $data) {
                        if ($data['value'] === 'unassigned') {
                            return $query->whereNull('user_id');
                        }

                        return $query->when($data['value'], fn ($q, $id) => $q->where('user_id', $id));
                    })
                    ->searchable()
                    ->preload(),
                TrashedFilter::make(),
            ])
            ->filtersFormColumns(fn () => auth()->user()?->isAdmin() ? 6 : 4)
            ->filtersLayout(FiltersLayout::AboveContentCollapsible)
            ->actions([
                Action::make('get_account')
                    ->label(__('system.get_account'))
                    ->visible(fn () => ! auth()->user()?->isFinance())
                    ->extraAttributes([
                        'style' => 'display: none !important;',
                    ])
                    ->modalHeading(__('system.account_claim.title'))
                    ->modalWidth('md')
                    ->form(function (Account $record) {
                        $emailAddress = strtolower($record->email?->email ?? '');
                        $isGmail = str_ends_with($emailAddress, '@gmail.com');

                        if ($isGmail) {
                            return [
                                Forms\Components\Placeholder::make('warning')
                                    ->label('')
                                    ->content(new HtmlString('
                                        <div style="color: #dc2626; font-weight: bold; padding: 12px; background-color: #fef2f2; border-radius: 8px; border: 1px solid #fecaca; font-size: 14px; line-height: 1.5;">
                                            ⚠️ '.__('system.gmail_warning.title').'<br>
                                            <span style="font-weight: 500; color: #991b1b; font-size: 13px;">'.__('system.gmail_warning.desc').'</span>
                                        </div>
                                    ')),
                                Forms\Components\Checkbox::make('verified')
                                    ->label(__('system.gmail_warning.checkbox'))
                                    ->required()
                                    ->accepted()
                                    ->validationMessages([
                                        'accepted' => __('system.gmail_warning.validation'),
                                    ]),
                            ];
                        }

                        return [
                            Forms\Components\Placeholder::make('msg')
                                ->label('')
                                ->content(new HtmlString(__('system.account_claim.desc'))),
                        ];
                    })
                    ->modalSubmitActionLabel(__('system.account_claim.submit'))
                    ->action(function (Account $record, array $data) {
                        $statuses = is_array($record->status) ? $record->status : [$record->status];

                        if (in_array('banned', $statuses)) {
                            Notification::make()
                                ->title(__('system.notifications.cannot_claim_banned'))
                                ->danger()
                                ->send();

                            return;
                        }

                        if (in_array('not_linked', $statuses)) {
                            Notification::make()
                                ->title(__('system.notifications.cannot_claim_not_linked'))
                                ->warning()
                                ->send();

                            return;
                        }

                        $record->update(['user_id' => auth()->id()]);

                        Notification::make()
                            ->title(__('system.account_claim.success'))
                            ->success()
                            ->send();
                    }),

                // Option B: Source Information as compact modal
                Action::make('view_source_info')
                    ->label('')
                    ->tooltip(__('system.labels.source_information'))
                    ->icon('heroicon-o-map-pin')
                    ->color('info')
                    ->modalHeading(__('system.labels.source_information'))
                    ->modalWidth('md')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('system.actions.close'))
                    ->form(function (Account $record): array {
                        $stateCode = $record->state ?? null;
                        $stateName = self::$usStates[$stateCode] ?? '';
                        $stateDisplay = $stateCode
                            ? ($stateName ? "{$stateCode} – {$stateName}" : $stateCode)
                            : 'N/A';

                        $created = $record->account_created_at
                            ? Carbon::parse($record->account_created_at)->format('d/m/Y')
                            : 'N/A';
                        $linked = $record->paypal_linked_at
                            ? Carbon::parse($record->paypal_linked_at)->format('d/m/Y')
                            : 'N/A';

                        return [
                            Forms\Components\Section::make(__('system.labels.source_information') ?: 'Account Source')
                                ->schema([
                                    Forms\Components\Placeholder::make('state_display')
                                        ->label(__('system.labels.state_us') ?: 'State')
                                        ->content($stateDisplay),
                                    Forms\Components\Placeholder::make('device_display')
                                        ->label(__('system.labels.device') ?: 'Device')
                                        ->content($record->device ?? 'N/A'),
                                    Forms\Components\Placeholder::make('created_display')
                                        ->label(__('system.labels.date_create') ?: 'Date Created')
                                        ->content($created),
                                ])->columns(3),

                            Forms\Components\Section::make(__('system.heading_infolist.paypal_information') ?: 'PayPal Information')
                                ->schema([
                                    Forms\Components\Placeholder::make('paypal_info_display')
                                        ->label(__('system.labels.address') ?: 'PayPal Info')
                                        ->content($record->paypal_info ?? 'N/A')
                                        ->columnSpanFull(),
                                    Forms\Components\Placeholder::make('device_linked_display')
                                        ->label(__('system.labels.device') ?: 'Device Linked')
                                        ->content($record->device_linked_paypal ?? 'N/A'),
                                    Forms\Components\Placeholder::make('linked_date_display')
                                        ->label(__('system.labels.linked_paypal_date') ?: 'Date Linked')
                                        ->content($linked),
                                ])->columns(2),
                        ];
                    }),

                Tables\Actions\ViewAction::make()
                    ->label('')
                    ->modalHeading(__('system.account_claim.section_title'))
                    ->tooltip(__('system.tooltips.details'))
                    ->icon('heroicon-o-eye')
                    ->color('gray'),

                Tables\Actions\ActionGroup::make([
                    Action::make('add_quick_order')
                        ->label(__('system.actions.add_quick_order'))
                        ->icon('heroicon-m-plus-circle')
                        ->color('success')
                        ->visible(fn () => ! auth()->user()?->isFinance())
                        ->modalWidth('2xl')
                        ->form([
                            Forms\Components\Grid::make(2)
                                ->schema([
                                    Forms\Components\TextInput::make('transaction_date')
                                        ->label(__('system.labels.transaction_date'))
                                        ->placeholder(__('system.placeholders.date_format'))
                                        ->nullable()
                                        ->default(null)
                                        ->mask('99/99/9999')
                                        ->rules(['date_format:d/m/Y'])
                                        ->dehydrateStateUsing(function ($state) {
                                            if (blank($state)) {
                                                return null;
                                            }
                                            try {
                                                return Carbon::createFromFormat('d/m/Y', $state)->format('Y-m-d');
                                            } catch (\Exception $e) {
                                                return null;
                                            }
                                        }),

                                    Forms\Components\TextInput::make('store_name')
                                        ->label(__('system.labels.store_name'))
                                        ->required()
                                        ->columnSpanFull(),
                                    Forms\Components\TextInput::make('order_id')
                                        ->label(__('system.labels.order_id')),
                                    Forms\Components\TextInput::make('order_value')
                                        ->label(__('system.labels.order_value'))
                                        ->numeric()
                                        ->prefix('$')
                                        ->required()
                                        ->reactive(),
                                    Forms\Components\TextInput::make('cashback_percent')
                                        ->label(__('system.labels.cashback_percent'))
                                        ->numeric()
                                        ->default(10)
                                        ->suffix('%'),
                                ]),
                        ])
                        ->action(function ($record, array $data) {
                            RebateTracker::create([
                                'account_id' => $record->id,
                                'user_id' => auth()->id(),
                                'transaction_date' => $data['transaction_date'],
                                'store_name' => $data['store_name'],
                                'order_id' => $data['order_id'],
                                'order_value' => $data['order_value'],
                                'cashback_percent' => $data['cashback_percent'],
                                'rebate_amount' => (float) $data['order_value'] * ($data['cashback_percent'] / 100),
                                'status' => 'clicked',
                            ]);
                        })
                        ->successNotificationTitle(__('system.notifications.added_successfully')),

                    Tables\Actions\RestoreAction::make(),
                    Tables\Actions\ForceDeleteAction::make()
                        ->visible(fn () => auth()->user()?->isAdmin()),
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),

                    Action::make('copy_full_info')
                        ->label(__('system.actions.copy'))
                        ->icon('heroicon-m-clipboard-document-check')
                        ->action(function ($record, $livewire) {
                            $header = ' | ID | Email Status | Year Created | Email Address | Email Password | Recovery Email | 2FA Code | Email Note | Platform | Platform Password | State | Device Create | Date Create | Platform Status | Platform Note | Holder | Personal Information | Device Linked | Date Linked PayPal | ';
                            $id = $record->id;
                            $emailStatus = $record->email?->status ?? 'N/A';
                            $emailstatusLabels = [
                                'active' => 'Live',
                                'disabled' => 'Disabled',
                                'locked' => 'Locked',
                            ];
                            $emailStatus = $emailstatusLabels[$emailStatus] ?? ucfirst($emailStatus);
                            $yearCreated = $record->email?->email_created_at ? $record->email->email_created_at->format('Y') : 'N/A';
                            $email = $record->email?->email ?? 'N/A';
                            $emailPass = $record->email?->email_password ?? 'N/A';
                            $recovery = $record->email?->recovery_email ?? 'N/A';
                            $twoFA = $record->email?->two_factor_code ?? 'N/A';
                            $emailNote = $record->email?->note ?? 'N/A';
                            $platform = $record->platform ?? 'N/A';
                            $platformPass = $record->password ?? 'N/A';
                            $stateName = self::$usStates[$record->state] ?? $record->state ?? 'N/A';
                            $platformDateCreated = $record->account_created_at ? $record->account_created_at->format('d/m/Y') : 'N/A';
                            $device = $record->device ?? 'N/A';
                            $paypal = $record->paypal_info ?? 'N/A';
                            $devicePaypal = $record->device_linked_paypal ?? 'N/A';
                            $dateLinked = $record->paypal_linked_at ? $record->paypal_linked_at->format('d/m/Y') : 'N/A';
                            $currentStatuses = is_array($record->status) ? $record->status : explode(',', (string) $record->status);
                            $platformstatus = collect($currentStatuses)
                                ->map(fn ($s) => ucfirst(trim($s)))
                                ->join(', ');
                            $note = $record->note ?? 'N/A';
                            $holder = $record->user?->name ?? 'N/A';

                            $singleLine = " | {$id} | {$emailStatus} | {$yearCreated} | {$email} | {$emailPass} | {$recovery} | {$twoFA} | {$emailNote} | {$record->platform} | {$record->password} | {$stateName} | {$device} | {$platformDateCreated} | {$platformstatus} | {$note} | {$holder} | {$paypal} | {$devicePaypal} | {$dateLinked} | \n";
                            $finalSingleLine = $header."\n".$singleLine;
                            $multiLine = "EMAIL INFORMATION:\n"."Email Status: {$emailStatus}\n"."Year Created: {$yearCreated}\n"."Email Address: {$email}\n"."Email Password: {$emailPass}\n"."Recovery Email: {$recovery}\n"."2FA Code: {$twoFA}\n"."Email Note: {$emailNote}\n"."--------------------------\n"."SOURCE & PLATFORM:\n"."Platform: {$platform}\n"."Platform Password: {$platformPass}\n"."State: {$stateName}\n"."Device Create: {$device}\n"."Date Create: {$platformDateCreated}\n"."Platform Status: {$platformstatus}\n"."Platform Note: {$note}\n"."Holder: {$holder}\n"."--------------------------\n"."PAYPAL INFORMATION:\n"."Personal Information: {$paypal}\n"."Device Linked: {$devicePaypal}\n"."Date Linked PayPal: {$dateLinked}\n";
                            $info = $finalSingleLine."\n\n".$multiLine;
                            $livewire->dispatch('copy-to-clipboard', text: $info);
                            Notification::make()
                                ->title(__('system.notifications.copied_successfully'))
                                ->success()
                                ->send();
                        }),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    BulkAction::make('export_accounts_to_google_sheet')
                        ->label(__('system.actions.export_to_sheet'))
                        ->icon('heroicon-o-table-cells')
                        ->color('success')
                        ->visible(fn () => auth()->user()?->isAdmin())
                        ->action(function (Collection $records, GoogleSyncService $syncService) {
                            try {
                                $syncService->syncAccounts($records);
                                Notification::make()
                                    ->title(__('system.notifications.synced_successfully'))
                                    ->success()
                                    ->send();
                            } catch (\Exception $e) {
                                Notification::make()
                                    ->title(__('system.notifications.sync_error'))
                                    ->body($e->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('get_bulk_accounts')
                        ->label(__('system.actions.claim_selected'))
                        ->icon('heroicon-m-user-plus')
                        ->color('success')
                        ->visible(fn () => ! auth()->user()?->isFinance())
                        ->requiresConfirmation()
                        ->action(function (Collection $records) {
                            $currentUserId = auth()->id();
                            foreach ($records as $record) {
                                if (empty($record->user_id)) {
                                    $record->update(['user_id' => $currentUserId]);
                                    $record->refresh();
                                }
                            }
                            Notification::make()
                                ->title(__('system.notifications.claimed_successfully'))
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('clear_date_create_selected')
                        ->label(__('system.actions.clear_date_create'))
                        ->icon('heroicon-m-x-circle')
                        ->color('danger')
                        ->visible(fn () => auth()->user()?->isAdmin())
                        ->requiresConfirmation()
                        ->action(function (Collection $records) {
                            foreach ($records as $record) {
                                $record->update(['account_created_at' => null]);
                            }
                            Notification::make()
                                ->title(__('system.notifications.update_success'))
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('copy_account_selected')
                        ->label(__('system.actions.copy_selected'))
                        ->icon('heroicon-m-clipboard-document-list')
                        ->color('warning')
                        ->action(function (Collection $records, $livewire) {
                            $header = ' | ID | Email Status | Year Created | Email Address | Email Password | Recovery Email | 2FA Code | Email Note | Platform | Platform Password | State | Device Create | Date Create | Platform Status | Platform Note | Holder | Personal Information | Device Linked | Date Linked PayPal | ';
                            $output = $header."\n";
                            foreach ($records as $record) {
                                $id = $record->id;
                                $emailStatus = $record->email?->status ?? 'N/A';
                                $emailstatusLabels = ['active' => 'Live', 'disabled' => 'Disabled', 'locked' => 'Locked'];
                                $emailStatus = $emailstatusLabels[$emailStatus] ?? ucfirst($emailStatus);
                                $yearCreated = $record->email?->email_created_at ? $record->email->email_created_at->format('Y') : 'N/A';
                                $email = $record->email?->email ?? 'N/A';
                                $emailPass = $record->email?->email_password ?? 'N/A';
                                $recovery = $record->email?->recovery_email ?? 'N/A';
                                $twoFA = $record->email?->two_factor_code ?? 'N/A';
                                $emailNote = $record->email?->note ?? 'N/A';
                                $platform = $record->platform ?? 'N/A';
                                $platformDateCreated = $record->account_created_at ? $record->account_created_at->format('d/m/Y') : 'N/A';
                                $device = $record->device ?? 'N/A';
                                $paypal = $record->paypal_info ?? 'N/A';
                                $devicePaypal = $record->device_linked_paypal ?? 'N/A';
                                $dateLinked = $record->paypal_linked_at ? $record->paypal_linked_at->format('d/m/Y') : 'N/A';
                                $currentStatuses = is_array($record->status) ? $record->status : [$record->status];
                                $stateName = self::$usStates[$record->state] ?? $record->state ?? 'N/A';
                                $platformstatus = collect($currentStatuses)
                                    ->map(fn ($s) => ucfirst(trim($s)))
                                    ->join(', ');

                                $accNote = $record->note ?? 'N/A';
                                $holder = $record->user?->name ?? 'N/A';

                                $output .= " | {$id} | {$emailStatus} | {$yearCreated} | {$email} | {$emailPass} | {$recovery} | {$twoFA} | {$emailNote} | {$record->platform} | {$record->password} | {$stateName} | {$device} | {$platformDateCreated} | {$platformstatus} | {$accNote} | {$holder} | {$paypal} | {$devicePaypal} | {$dateLinked} | \n";
                            }

                            $livewire->dispatch('copy-to-clipboard', text: $output);

                            Notification::make()
                                ->title(__('system.actions.copied'))
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\RestoreBulkAction::make()
                        ->visible(fn () => auth()->user()?->isAdmin()),
                    Tables\Actions\ForceDeleteBulkAction::make()
                        ->visible(fn () => auth()->user()?->isAdmin()),
                    Tables\Actions\DeleteBulkAction::make()
                        ->visible(fn () => auth()->user()?->isAdmin()),
                ]),
            ]);
    }

    // bootHasAccountSchema ĐÃ XÓA (FIX #7):
    // Sync Account lên Sheet được thực hiện qua Observer hoặc gọi trực tiếp
    // syncSingleAccountToSheet() trong action. Không dùng boot() để tránh
    // double-dispatch nếu sau này thêm AccountObserver.
}
