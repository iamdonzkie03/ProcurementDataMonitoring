USE procurement;

-- Electronic signature image for the single Division/Department Head.
ALTER TABLE divisions
  ADD COLUMN electronic_signature VARCHAR(255) NULL AFTER head_position_designation;

-- Electronic signature image associated with an Area/Unit.
ALTER TABLE areas
  ADD COLUMN electronic_signature VARCHAR(255) NULL AFTER code;
