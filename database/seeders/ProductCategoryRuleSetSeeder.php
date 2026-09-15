<?php

namespace Database\Seeders;

use App\Enums\ProductCategory;
use App\Models\ProductCategory as ProductCategoryModel;
use App\Models\ProductCategoryRuleSet;
use App\Services\PriceList\ProductNameNormalizer;
use Illuminate\Database\Seeder;

class ProductCategoryRuleSetSeeder extends Seeder
{
    public function run(): void
    {
        $definitions = self::definitions();
        $checksum = hash('sha256', json_encode($definitions, JSON_UNESCAPED_UNICODE));
        if (ProductCategoryRuleSet::query()->where('status', 'published')->where('checksum', $checksum)->exists()) {
            return;
        }
        ProductCategoryRuleSet::query()->where('status', 'published')->update(['status' => 'retired']);
        $set = ProductCategoryRuleSet::create(['version' => ((int) ProductCategoryRuleSet::max('version')) + 1, 'checksum' => $checksum, 'status' => 'published', 'published_at' => now()]);
        foreach ($definitions as $definition) {
            $definition['product_category_id'] = ProductCategoryModel::query()->where('label', $definition['category'])->value('id');
            $set->rules()->create($definition);
        }
    }

    /** @return list<array<string, mixed>> */
    public static function definitions(): array
    {
        $keywords = self::keywords();
        $normalizer = app(ProductNameNormalizer::class);
        $definitions = [];
        foreach (ProductCategory::ordered() as $priority => $category) {
            foreach (explode('|', $keywords[$category->value] ?? '') as $keyword) {
                if ($keyword === '') {
                    continue;
                }
                $matcher = implode(' ', $normalizer->normalize($keyword)['tokens']);
                $definitions[] = ['category' => $category->value, 'literal_keyword' => $keyword, 'normalized_matcher' => $matcher, 'matcher_mode' => 'token_phrase', 'priority' => $priority + 1, 'confidence' => mb_strlen($matcher) >= 5 ? 95 : 75, 'context_requirements' => in_array($keyword, ['паст', 'паст.'], true) ? ['__pharma_indicator__'] : [], 'context_exclusions' => [], 'supersedes_categories' => self::supersedes($category, $keyword)];
            }
        }

        return $definitions;
    }

    /** @return array<string, string> */
    private static function keywords(): array
    {
        return [
            'Таблетки' => 'таб|таб.|табл|табл.|тб|тб.|шип. таб|шип. таб.|шип. табл|шип. табл.|жев. таб|жев. таб.|жев. табл|жев. табл.|жев. тб|ваг. таб|ваг. таб.|ваг. табл|ваг. табл.|таблет|таблетка|таблетки', 'Пастилки' => 'пастилки|пастилка|пастил|жев паст|жев. паст|паст|паст.|леденцы|леденци|ледены|леден.',
            'Капсулы' => 'капс|капс.|капсул|капсула|капсулы|ваг кап|ваг. кап|ваг капс|ваг. капс|ваг капс.|ваг. капс.|вагин. капс|вагин. капс.', 'Драже' => 'драже|драж|др|др.', 'Сироп' => 'сироп|сироп.|сир|сир.|шарбат|шарбати',
            'Суспензия' => 'сусп|сусп.|суспен|суспенз|суспенз.|суспензия|пор. д/сусп.|пор д/сусп|пор. для сусп.|пор для сусп|пор д/приг суспензии|пор. д/приг. сусп.|пор. д/приг. суспензии',
            'Свечи' => 'св|св.|св ваг|св. ваг|св. ваг.|св рект|св. рект|св. рект.|свеч|свеча|свечи|фитосвеча|фитосвечи|супп|супп.|супп рект|супп. рект.|супп ваг|супп. ваг.|суппоз|суппоз.|суппозиторий|суппозитории|шамъчаҳои',
            'Мазь' => 'маз|маз.|мазь|мазью', 'Крем' => 'крем|крема|кремом|кремы|крем-бальзам', 'Гель' => 'гель|гель-бальзам|гель-бальз.|гель/баль.|эмульгель',
            'Капли' => 'капли|капля|капл.|гл капли|гл. капли|гл/капли|гл/капл.|глазные капли|капли ушные|уш капли|ушн. капли|капли назальные|назальные капли|қатраи чашм', 'Спрей' => 'спрей|спрей назальный|спрей назаль|назальный спрей|spray', 'Аэрозоль' => 'аэр|аэр.|аэрозоль|аэрозол|аэразоль', 'Раствор' => 'р-р|рр|р/р|раствор|масл. раствор|р-р масляный|маҳлул',
            'Ампулы' => 'амп|амп.|ампула|ампулы',
            'Флаконы' => 'фл|фл.|флак|флак.|флакон|флаконы',
            'Инъекции' => 'инъ|инъ.|инъек|инъекция|инъекции|инъекций|р-р д/ин|р-р д/ин.|р-р д/инъ|р-р д/инъ.|р-р для инъ|р-р для инъекции|р/р для инъ|пор д/ин|пор. д/ин|пор д/инъ|пор. д/инъ.|д/ин|д/ин.|д-ин|д-ин.|р-р д/инф|р-р д/инф.|р-р для инф|р-р для инфузии|р-р для инфузий|р-р инфуз|р-р инфуз.|инфуз|инфуз.|инфузия|инфузии|инфузий|в/м|в-м|в/в|в-в|п/к|п-к|лиоф. пор. д/ин.|лиоф. пор. д-ин.',
            'Ингаляции' => 'ингал|ингал.|ингаляция|ингаляции|ингаляций|ингалятор|р-р д/инг|р-р д/инг.|р-р д/ингл.|р-р для ингаляции|р-р для ингаляций|сусп. д/инг.|суспензия для ингаляций|д/инг|д/инг.|д-инг|д-инг.|д-ингалация|ингалация|ингаляця',
            'Порошок' => 'пор|пор.|порош|порош.|порошок|поро.|порш|порвнутрь', 'Гранулы' => 'гран|гран.|гранул|гранул.|гранула|гранулы', 'Пластырь' => 'пластырь|пластыр|лейкопластырь|мазольный лейкопластырь', 'Эмульсия' => 'эмульсия|эмульс.', 'Линимент' => 'линим|линим.|линимент', 'Лосьон' => 'лосьон|лосьен', 'Паста' => 'паста', 'Масло' => 'масло|масл|масл.|в масле|масля|масляный|маслени', 'Настойка' => 'настойка|н-ка', 'Шампунь' => 'шампунь|шампун|шамп.|шампнуь', 'Микроклизма' => 'микроклизма|микроклизмы', 'Жевательная резинка' => 'жевательная резинка|жев. резинка', 'Экстракт' => 'экстракт|э-кт',
        ];
    }

    /** @return list<string> */
    private static function supersedes(ProductCategory $category, string $keyword): array
    {
        return match ($category) {
            ProductCategory::Inhalations, ProductCategory::Ampoules, ProductCategory::Vials, ProductCategory::Injections, ProductCategory::Suspension => ['Раствор', 'Порошок'],
            ProductCategory::Gel => $keyword === 'эмульгель' ? ['Эмульсия'] : [],
            default => [],
        };
    }
}
