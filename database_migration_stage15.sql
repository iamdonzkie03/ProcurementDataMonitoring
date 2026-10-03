USE procurement;

-- Add the position/designation of the single Division/Department Head.
ALTER TABLE divisions
  ADD COLUMN head_position_designation VARCHAR(150) NULL AFTER division_head;
