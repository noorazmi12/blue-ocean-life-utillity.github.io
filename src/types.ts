export type UserRole = 'admin' | 'operator' | 'viewer';
export type DeviceType = 'owc' | 'wave_sensor' | 'water_sensor' | 'camera' | 'gateway';
export type DeviceStatus = 'online' | 'offline' | 'maintenance';
export type CalibrationStatus = 'calibrated' | 'due' | 'overdue' | 'none';

export interface User {
  id: number;
  username: string;
  email: string;
  password_hash: string;
  full_name: string;
  role: UserRole;
  is_active: number;
  last_login: string | null;
  created_at: string;
}

export interface Device {
  id: number;
  device_code: string;
  device_name: string;
  device_type: DeviceType;
  status: DeviceStatus;
  is_active: number;
  api_token: string | null;
  last_communication: string | null;
  calibration_status: CalibrationStatus;
  calibration_date: string | null;
  notes: string | null;
  created_at: string;
}

export interface DeviceLocation {
  id: number;
  device_id: number;
  latitude: number;
  longitude: number;
  location_name: string | null;
  installed_at: string | null;
  notes: string | null;
  updated_at: string | null;
}

export interface Threshold {
  id: number;
  parameter: string;
  species: string;
  min_value: number | null;
  max_value: number | null;
  warn_span: number | null;
  unit: string | null;
  updated_by: number | null;
  updated_at: string | null;
}

export interface SystemSetting {
  id: number;
  setting_key: string;
  setting_value: string;
  description: string | null;
  updated_at: string | null;
}

export interface FishSpecies {
  id: number;
  name: string;
  latin_name: string | null;
  category: 'laut' | 'tawar' | 'brackish';
  cycle_months_min: number | null;
  cycle_months_max: number | null;
  target_weight_kg: number | null;
  lw_a: number;
  lw_b: number;
  optimal_temp_min: number | null;
  optimal_temp_max: number | null;
  optimal_ph_min: number | null;
  optimal_ph_max: number | null;
  optimal_salinity_min: number | null;
  optimal_salinity_max: number | null;
  description: string | null;
  is_active: number;
  created_at: string;
  updated_at: string | null;
}

export interface KjaSite {
  id: number;
  slug: string;
  site_name: string;
  kabupaten: string;
  kecamatan: string | null;
  latitude: number;
  longitude: number;
  depth_m: number | null;
  depth_source: string | null;
  shelter: 'teluk' | 'semi' | 'terbuka';
  access_note: string | null;
  recommended_species: string | null;
  score_depth: number;
  score_shelter: number;
  score_access: number;
  score_conflict: number;
  suitability_score: number;
  suitability_class: 'S1' | 'S2' | 'S3' | 'N';
  constraints_note: string | null;
  notes: string | null;
  is_active: number;
  created_at: string;
  updated_at: string | null;
}

export interface WaterMeasurement {
  id: number;
  device_id: number;
  measured_at: string;
  temp_sensor_c: number | null;
  sst_c: number | null;
  salinity_psu: number | null;
  ph: number | null;
  volt_ph_v: number | null;
  turbidity_ntu: number | null;
  depth_m: number | null;
  is_simulated: number;
  created_at: string;
}

export interface OwcMeasurement {
  id: number;
  device_id: number;
  measured_at: string;
  power_w: number;
  voltage_v: number;
  current_a: number;
  energy_wh: number;
  battery_pct: number | null;
  is_simulated: number;
  created_at: string;
}

export interface WaveMeasurement {
  id: number;
  device_id: number;
  measured_at: string;
  wave_height_m: number;
  wave_period_s: number;
  wave_direction_deg: number | null;
  wind_speed_ms: number | null;
  wind_direction_deg: number | null;
  is_simulated: number;
  created_at: string;
}

export interface FishcountResult {
  id: number;
  device_id: number | null;
  image_path: string | null;
  media_type: 'image' | 'video';
  media_duration_s: number | null;
  frames_analyzed: number | null;
  thumb_path: string | null;
  detected_count: number | null;
  avg_length_cm: number | null;
  biomass_kg: number | null;
  confidence_pct: number | null;
  processing_status: string;
  status_message: string | null;
  is_simulated: number;
  is_manual: number;
  manual_count: number | null;
  validated_by: number | null;
  validated_at: string | null;
  notes: string | null;
  created_at: string;
}

export interface AlertItem {
  id: number;
  device_id: number | null;
  parameter: string;
  severity: 'info' | 'warning' | 'critical';
  title: string;
  message: string;
  actual_value: number | null;
  threshold_value: number | null;
  occurred_at: string;
  status: 'open' | 'resolved';
  resolved_by: number | null;
  resolved_at: string | null;
  is_simulated: number;
  created_at: string;
}
