-- WARNING: This schema is for context only and is not meant to be run.
-- Table order and constraints may not be valid for execution.

CREATE TABLE public.users (
  id bigint GENERATED ALWAYS AS IDENTITY NOT NULL,
  user_type character varying NOT NULL,
  name character varying NOT NULL,
  profile_image text,
  department character varying,
  email character varying UNIQUE,
  contact_number character varying,
  birthday date,
  address text,
  area_of_work text,
  password text NOT NULL,
  created_at timestamp with time zone DEFAULT now(),
  signature_image text,
  CONSTRAINT users_pkey PRIMARY KEY (id)
);
CREATE TABLE public.work_request (
  id bigint GENERATED ALWAYS AS IDENTITY NOT NULL,
  user_id bigint NOT NULL,
  requesters_name character varying NOT NULL,
  department character varying NOT NULL,
  type_of_request character varying NOT NULL,
  date_requested timestamp with time zone DEFAULT now(),
  description_of_work text,
  location character varying,
  time_duration interval,
  no_of_personnel_needed integer,
  image_of_work text,
  signature_of_requester text,
  time_start time without time zone,
  date_start date,
  time_finish time without time zone,
  date_finish date,
  availability_of_materials boolean DEFAULT false,
  staff_assigned character varying,
  image_of_work_done text,
  meso_signature text,
  campus_director_signature text,
  status character varying DEFAULT 'Pending'::character varying,
  CONSTRAINT work_request_pkey PRIMARY KEY (id),
  CONSTRAINT fk_user_id FOREIGN KEY (user_id) REFERENCES public.users(id)
);