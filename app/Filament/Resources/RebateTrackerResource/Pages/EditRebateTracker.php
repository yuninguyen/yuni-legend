<?php

namespace App\Filament\Resources\RebateTrackerResource\Pages;

use App\Filament\Resources\RebateTrackerResource;
use App\Filament\Resources\Traits\HasUsStates;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\EditRecord;
use App\Filament\Resources\Traits\RedirectsToIndex; // <-- Nhúng Trait

class EditRebateTracker extends EditRecord
{
    use RedirectsToIndex; // <-- Gọi ra sử dụng, xong! Không cần viết lại hàm getRedirectUrl nữa.
    use HasUsStates;

    protected static string $resource = RebateTrackerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Nút Thêm mới cho chính Account này
            Actions\Action::make('add_another')
                ->label('Add another order for this account')
                ->icon('heroicon-m-arrow-path')
                ->color('info')
                ->modalHeading('Create a copy for the new order?')
                ->modalDescription('The system will create a new record with the same Account and User for you to continue entering the information.')
                ->form([
                    Forms\Components\TextInput::make('device')
                        ->label(__('system.labels.device'))
                        ->placeholder('iOS, VMware, BitBrowser Antidetect...'),
                    Forms\Components\Select::make('state')
                        ->label(__('system.labels.state_us'))
                        ->searchable()
                        ->options(self::$usStates),
                    Forms\Components\Textarea::make('note')
                        ->label(__('system.labels.note'))
                        ->rows(5),
                ])
                ->fillForm(fn ($record) => [
                    'device' => $record->device,
                    'state' => $record->state,
                    'note' => $record->note,
                ])
                ->action(function ($record, array $data) {
                    // Nhân bản bản ghi hiện tại
                    $newOrder = $record->replicate()->fill([
                        'store_name' => '', // Xóa tên store cũ để nhập mới
                        'order_id' => '',   // Xóa ID cũ
                        'order_value' => 0,
                        'rebate_amount' => 0,
                        'status' => 'clicked',
                        'device' => $data['device'],
                        'state' => $data['state'],
                        'note' => $data['note'],
                    ]);

                    $newOrder->save();

                    // Chuyển hướng ngay lập tức đến trang Edit của đơn mới vừa tạo
                    $this->redirect($this->getResource()::getUrl('edit', ['record' => $newOrder]));
                }),

            Actions\DeleteAction::make(),
        ];
    }
}
