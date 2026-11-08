<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    private const CATEGORIES = [
        'Textbooks and Educational Materials' => [
            'Textbooks',
            'Reviewers',
            'Notebooks',
            'Reference Materials',
        ],
        'Electronics and Gadgets' => [
            'Laptops',
            'Smartphones',
            'Tablets',
            'Keyboards',
            'Computer Accessories',
            'Headphones and Earphones',
            'Other Electronic Devices',
        ],
        'Clothing and Accessories' => [
            'T-shirts',
            'Pants',
            'Jackets',
            'Shoes',
            'Bags',
            'Accessories',
        ],
        'Dormitory Essentials' => [
            'Electric Fans',
            'Lamps',
            'Storage Organizers',
            'Small Appliances',
            'Other Dormitory Essentials',
        ],
        'School Supplies' => [
            'Pens and Pencils',
            'Calculators',
            'Art Materials',
            'Other School Supplies',
        ],
    ];

    public function run(): void
    {
        foreach (self::CATEGORIES as $parentName => $children) {
            $parent = Category::query()->firstOrCreate([
                'parent_category_id' => null,
                'category_name' => $parentName,
            ]);

            foreach ($children as $childName) {
                Category::query()->firstOrCreate([
                    'parent_category_id' => $parent->id,
                    'category_name' => $childName,
                ]);
            }
        }
    }
}
