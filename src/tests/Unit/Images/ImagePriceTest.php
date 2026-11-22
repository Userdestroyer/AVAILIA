<?php

namespace Tests\Unit\Images;

use App\Services\Images\ImagePrice;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class ImagePriceTest extends TestCase
{
    public static function prices(): array
    {
        $tiers = ['tiers' => [
            ['conditions' => [], 'cost_rub' => '6.75'],
            ['conditions' => ['image_resolution=1K'], 'cost_rub' => '3.00'],
            ['conditions' => ['image_resolution=2K'], 'cost_rub' => '4.50'],
            ['conditions' => ['quality=high', 'input_images_count=2'], 'cost_rub' => '10.30'],
            ['conditions' => ['input_images_count=2'], 'cost_rub' => '5.30'],
        ]];

        return [
            'за запрос' => [['per_request' => '2.90'], [], 0, 2.9],
            'ступень по разрешению' => [$tiers, ['image_resolution' => '2K'], 0, 4.5],
            // Самая точная подходящая ступень: два условия важнее одного
            'качество и число референсов' => [$tiers, ['quality' => 'high'], 2, 10.3],
            'только число референсов' => [$tiers, ['quality' => 'basic'], 2, 5.3],
            'ничего не подошло — общая ступень' => [$tiers, ['image_resolution' => '8K'], 0, 6.75],
            // Прайс по токенам — цену картинки заранее не знаем
            'по токенам' => [['prompt_per_million' => '59.3', 'image_output_per_million' => '7118'], [], 0, null],
        ];
    }

    #[Group('U1')]
    #[DataProvider('prices')]
    #[TestDox('Цена картинки — за запрос или самой точной подходящей ступенью прайса (разрешение, качество, число референсов)')]
    public function test_estimate(array $pricing, array $params, int $references, ?float $expected): void
    {
        $this->assertSame($expected, ImagePrice::estimate($pricing, $params, $references));
    }
}
