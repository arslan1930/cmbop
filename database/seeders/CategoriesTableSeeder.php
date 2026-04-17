<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Category;

class CategoriesTableSeeder extends Seeder
{
    public function run()
    {
        $categories = [
            // Business & Finance
            ['name' => 'Business & Finance', 'group' => 'Business & Finance'],
            ['name' => 'Banking & Insurance', 'group' => 'Business & Finance'],
            ['name' => 'Crypto & Blockchain', 'group' => 'Business & Finance'],
            ['name' => 'Real Estate & Property', 'group' => 'Business & Finance'],
            ['name' => 'Construction & Architecture', 'group' => 'Business & Finance'],
            ['name' => 'Legal Services', 'group' => 'Business & Finance'],
            ['name' => 'Marketing, PR & Advertising', 'group' => 'Business & Finance'],
            ['name' => 'SaaS & B2B Software', 'group' => 'Business & Finance'],
            ['name' => 'Finance for SMEs', 'group' => 'Business & Finance'],
            
            // Technology
            ['name' => 'Technology & Gadgets', 'group' => 'Technology'],
            ['name' => 'Cybersecurity & Data Privacy', 'group' => 'Technology'],
            ['name' => 'Telecommunications & Internet Providers', 'group' => 'Technology'],
            ['name' => 'Smart Home & IoT', 'group' => 'Technology'],
            
            // E-commerce & Retail
            ['name' => 'E-commerce & Retail', 'group' => 'E-commerce & Retail'],
            ['name' => 'Logistics & Supply Chain', 'group' => 'E-commerce & Retail'],
            
            // Automotive
            ['name' => 'Automotive', 'group' => 'Automotive'],
            
            // Travel & Hospitality
            ['name' => 'Travel & Tourism', 'group' => 'Travel & Hospitality'],
            ['name' => 'Hospitality', 'group' => 'Travel & Hospitality'],
            ['name' => 'Food & Beverage', 'group' => 'Travel & Hospitality'],
            
            // Health & Wellness
            ['name' => 'Health & Wellness', 'group' => 'Health & Wellness'],
            ['name' => 'Medical & Clinics', 'group' => 'Health & Wellness'],
            ['name' => 'Pharma & Supplements', 'group' => 'Health & Wellness'],
            ['name' => 'Fitness & Sports', 'group' => 'Health & Wellness'],
            
            // Lifestyle
            ['name' => 'Beauty & Skincare', 'group' => 'Lifestyle'],
            ['name' => 'Fashion & Luxury', 'group' => 'Lifestyle'],
            ['name' => 'Home & Garden', 'group' => 'Lifestyle'],
            ['name' => 'Parenting & Family', 'group' => 'Lifestyle'],
            ['name' => 'Dating & Relationships', 'group' => 'Lifestyle'],
            ['name' => 'Pets & Veterinary', 'group' => 'Lifestyle'],
            
            // Energy & Environment
            ['name' => 'Energy', 'group' => 'Energy & Environment'],
            ['name' => 'Environment & Sustainability', 'group' => 'Energy & Environment'],
            
            // Industry
            ['name' => 'Manufacturing & Industry', 'group' => 'Industry'],
            ['name' => 'Agriculture & Agritech', 'group' => 'Industry'],
            ['name' => 'Maritime & Shipping', 'group' => 'Industry'],
            ['name' => 'Aviation & Airports', 'group' => 'Industry'],
            
            // Education & Careers
            ['name' => 'Education & E-learning', 'group' => 'Education & Careers'],
            ['name' => 'Jobs & Recruitment', 'group' => 'Education & Careers'],
            ['name' => 'HR & Payroll', 'group' => 'Education & Careers'],
            
            // Entertainment
            ['name' => 'Gaming & Esports', 'group' => 'Entertainment'],
            ['name' => 'Entertainment & Media', 'group' => 'Entertainment'],
            ['name' => 'News & Politics', 'group' => 'Entertainment'],
            
            // Events & Social
            ['name' => 'Events, Conferences & Trade Fairs', 'group' => 'Events & Social'],
            ['name' => 'NGOs, Charity & Social Impact', 'group' => 'Events & Social'],
            
            // Other
            ['name' => 'Outdoor & Adventure', 'group' => 'Other'],
            ['name' => 'Regional/Local', 'group' => 'Other'],
        ];
        
        foreach ($categories as $category) {
            Category::create($category);
        }
    }
}