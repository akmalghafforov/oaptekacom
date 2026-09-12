<?php

namespace Database\Seeders;

use App\Enums\OrganizationType;
use App\Enums\TradeMode;
use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\SupplierSenderAddress;
use App\Models\User;
use Illuminate\Database\Seeder;

class SuppliersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach ($this->suppliers() as $supplier) {
            $organization = Organization::query()->updateOrCreate(
                ['phone' => $supplier['phone'], 'type' => OrganizationType::Wholesaler],
                [
                    'name' => $supplier['name'],
                    'city' => $supplier['city'],
                    'status' => 'active',
                    'supplier_mode' => TradeMode::Supplier->value,
                ],
            );

            User::query()->updateOrCreate(
                ['phone' => $supplier['phone'], 'role' => UserRole::Wholesaler],
                [
                    'name' => $supplier['name'],
                    'email' => null,
                    'password' => null,
                    'organization_id' => $organization->id,
                    'active_trade_mode' => TradeMode::Supplier,
                    'phone_verified_at' => null,
                ],
            );

            if ($supplier['email'] !== null) {
                $normalizedEmail = mb_strtolower(trim($supplier['email']));

                SupplierSenderAddress::query()->updateOrCreate(
                    ['normalized_email' => $normalizedEmail],
                    ['email' => $normalizedEmail, 'supplier_organization_id' => $organization->id],
                );
            }
        }
    }

    /**
     * @return list<array{name: string, city: string, phone: string, email: ?string}>
     */
    private function suppliers(): array
    {
        return [
            ['name' => 'Казфарма', 'city' => 'Худжанд', 'phone' => '+992926444945', 'email' => 'davotjk@mail.ru'],
            ['name' => 'Сифат', 'city' => 'Худжанд', 'phone' => '+992928003933', 'email' => 'sifatapteka@mail.ru'],
            ['name' => 'Эмити-Интернешнл', 'city' => 'Худжанд', 'phone' => '+992927703315', 'email' => 'emiti-tj@mail.ru'],
            ['name' => 'Дармонбахш', 'city' => 'Худжанд', 'phone' => '+992923004008', 'email' => 'darmonbaxsh-2019@mail.ru'],
            ['name' => 'Соф Фарм', 'city' => 'Худжанд', 'phone' => '+992928144921', 'email' => 'sof.farm@inbox.ru'],
            ['name' => 'Саховат', 'city' => 'Худжанд', 'phone' => '+992928004001', 'email' => 'sakhovatprice@mail.ru'],
            ['name' => 'Зам-Зам', 'city' => 'Худжанд', 'phone' => '+992928854218', 'email' => 'apt.zam-zam@mail.ru'],
            ['name' => 'ОсонФарм - Аличон', 'city' => 'Худжанд', 'phone' => '+992927430570', 'email' => 'mirali-91@mail.ru'],
            ['name' => 'Карим Фарм', 'city' => 'Худжанд', 'phone' => '+992928130014', 'email' => 'best.farm@bk.ru'],
            ['name' => 'Канзи Шифо', 'city' => 'Худжанд', 'phone' => '+992929620173', 'email' => 'dusti0411@mail.ru'],
            ['name' => 'НурФарма', 'city' => 'Худжанд', 'phone' => '+992926043382', 'email' => 'nurfarm9992@mail.ru'],
            ['name' => 'Осон-Фарм', 'city' => 'Худжанд', 'phone' => '+992923333200', 'email' => 'osonfarm.khujand@mail.ru'],
            ['name' => 'Дармон', 'city' => 'Худжанд', 'phone' => '+992923004545', 'email' => 'apteka.darmon@mail.ru'],
            ['name' => 'Фарм Мед ул.Мир', 'city' => 'Худжанд', 'phone' => '+992928184449', 'email' => 'farm-med07@mail.ru'],
            ['name' => 'Ёсин-М', 'city' => 'Худжанд', 'phone' => '+992929419119', 'email' => 'manuchehr76@mail.ru'],
            ['name' => 'Мижгона', 'city' => 'Худжанд', 'phone' => '+992927034219', 'email' => '5333317@mail.ru'],
            ['name' => 'Имдоди-Шифо №1', 'city' => 'Истаравшан', 'phone' => '+992505008282', 'email' => 'mahksud9444@gmail.com'],
            ['name' => 'Форс', 'city' => 'Худжанд', 'phone' => '+992926154900', 'email' => 'a_toshpulotov2222@mail.ru'],
            ['name' => 'Амири', 'city' => 'Истаравшан', 'phone' => '+992929330666', 'email' => 'aptekaamiri@gmail.com'],
            ['name' => 'Мин', 'city' => 'Худжанд', 'phone' => '+992929210999', 'email' => 'min.sklad7@gmail.com'],
            ['name' => 'Билол', 'city' => 'Худжанд', 'phone' => '+992929719500', 'email' => 'apteka.bilol@mail.ru'],
            ['name' => 'ООО Эконом', 'city' => 'Худжанд', 'phone' => '+992927663933', 'email' => 'mega-88@mail.ru'],
            ['name' => 'Фарм-Плюс', 'city' => 'Худжанд', 'phone' => '+992929101691', 'email' => 'farmplus2023@mail.ru'],
            ['name' => 'Истиклол', 'city' => 'Истаравшан', 'phone' => '+992985076366', 'email' => null],
            ['name' => 'Томирис Истаравшан', 'city' => 'Истаравшан', 'phone' => '+992918807447', 'email' => 'mkhusrav.93@gmail.com'],
            ['name' => 'Сино-Фарм', 'city' => 'Худжанд', 'phone' => '+992920133434', 'email' => 'aziz-6707@mail.ru'],
            ['name' => 'Мерос Фарма', 'city' => 'Худжанд', 'phone' => '+992712224444', 'email' => 'nozim.abdudzhalilov.98@bk.ru'],
            ['name' => 'Аптека Даво +', 'city' => 'Истаравшан', 'phone' => '+992988090099', 'email' => 'tau77az@mail.ru'],
            ['name' => 'Пойтахт', 'city' => 'Душанбе', 'phone' => '+992945320303', 'email' => null],
            ['name' => 'Дорухонаи Дармон', 'city' => 'Душанбе', 'phone' => '+992000900017', 'email' => null],
            ['name' => 'Мехр-Фарм', 'city' => 'Худжанд', 'phone' => '+992928886919', 'email' => 'mehr.farm@mail.ru'],
            ['name' => 'Дорухонаи Соф-Фарм', 'city' => 'Душанбе', 'phone' => '+992100300082', 'email' => null],
            ['name' => 'Мухаммад', 'city' => 'Истаравшан', 'phone' => '+992943330303', 'email' => 'eshon0666@gmail.com'],
            ['name' => 'Томирис Душанбе', 'city' => 'Душанбе', 'phone' => '+992918628066', 'email' => 'mgm-91@bk.ru'],
            ['name' => 'Дорухонаи Авиценна', 'city' => 'Душанбе', 'phone' => '+992981007005', 'email' => null],
            ['name' => 'Дорухонаи АКТИВ-ФАРМ', 'city' => 'Душанбе', 'phone' => '+992944100005', 'email' => null],
            ['name' => 'ЧДММ Кавсар Тичорат', 'city' => 'Душанбе', 'phone' => '+992904051416', 'email' => null],
            ['name' => 'Дорухонаи JSB (Сухроб ТБШ)', 'city' => 'Душанбе', 'phone' => '+992557779094', 'email' => null],
            ['name' => 'Дорухонаи Олами Дорухо', 'city' => 'Душанбе', 'phone' => '+992005555858', 'email' => null],
            ['name' => 'Дорухонаи Хушбахтиён', 'city' => 'Душанбе', 'phone' => '+992904525209', 'email' => null],
            ['name' => 'Дорухонаи Шифои Сино', 'city' => 'Душанбе', 'phone' => '+992988398383', 'email' => null],
            ['name' => 'Дорухонаи Анис', 'city' => 'Душанбе', 'phone' => '+992987799999', 'email' => null],
            ['name' => 'Дорухонаи Табобат', 'city' => 'Душанбе', 'phone' => '+992000406018', 'email' => null],
            ['name' => 'Дорухонаи Вита Фарма', 'city' => 'Душанбе', 'phone' => '+992918787033', 'email' => null],
            ['name' => 'Дорухонаи Бестфарм', 'city' => 'Душанбе', 'phone' => '+992918427580', 'email' => null],
            ['name' => 'Дорухонаи Мухаммад А', 'city' => 'Душанбе', 'phone' => '+992112122912', 'email' => null],
            ['name' => 'Дорухонаи Сифат фарма', 'city' => 'Душанбе', 'phone' => '+992970400200', 'email' => null],
            ['name' => 'Дорухонаи Даво', 'city' => 'Душанбе', 'phone' => '+992886009279', 'email' => null],
            ['name' => 'Сифат-Фарма', 'city' => 'Худжанд', 'phone' => '+992928076931', 'email' => 'sher4669@inbox.ru'],
            ['name' => 'Дорухонаи Эконом', 'city' => 'Душанбе', 'phone' => '+992918573030', 'email' => null],
        ];
    }
}
