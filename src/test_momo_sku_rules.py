import unittest
import sys
import os
import logging

# Add src to path
sys.path.append(os.path.join(os.path.dirname(__file__), '..'))

from src.momo_client import MomoClient

class TestMomoSKU(unittest.TestCase):
    def setUp(self):
        # Mock config
        self.config = {}
        self.client = MomoClient(self.config)

    def test_clean_spec_value(self):
        # Test cleaning Chinese characters
        self.assertEqual(self.client._clean_spec_value("L號"), "L")
        self.assertEqual(self.client._clean_spec_value("85CM"), "85CM")
        self.assertEqual(self.client._clean_spec_value("深灰(1220)"), "(1220)") # Assuming we keep parens
        self.assertEqual(self.client._clean_spec_value("黑色"), "")

    def test_sku_generation_logic(self):
        # Simulate the logic inside get_inventory
        
        def generate_sku(part_no, goodsdt_info):
            final_sku = part_no
            if goodsdt_info and '/' in goodsdt_info:
                suffix = goodsdt_info.split('/')[-1]
                clean_suffix = self.client._clean_spec_value(suffix)
                if clean_suffix:
                    final_sku = f"{part_no}/{clean_suffix}"
            return final_sku

        # Case 1: No slash (e.g., "無", "深灰(1220)")
        self.assertEqual(generate_sku("2520451-01", "無"), "2520451-01")
        self.assertEqual(generate_sku("2430286-06", "深灰(1220)"), "2430286-06")
        
        # Case 2: With slash (e.g., "Color/Size")
        self.assertEqual(generate_sku("2040076-61", "Color/L號"), "2040076-61/L")
        self.assertEqual(generate_sku("2040076-61", "Color/85CM"), "2040076-61/85CM")
        
        # Case 3: With slash but suffix becomes empty after cleaning
        self.assertEqual(generate_sku("12345", "Color/黑色"), "12345") 
        # Wait, if suffix is empty, it falls back to part_no? 
        # My implementation: if clean_suffix: final_sku = ...
        # So yes, it stays part_no.
        
        # Case 4: Multiple slashes (take last part)
        self.assertEqual(generate_sku("12345", "A/B/C"), "12345/C")

if __name__ == '__main__':
    logging.basicConfig(level=logging.INFO)
    unittest.main()
