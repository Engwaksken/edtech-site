<?php
declare(strict_types=1);

function rs_report_types(): array
{
    return [
        'session_report' => [
            'label'       => 'Mentorship Session Report',
            'short'       => 'Session Report',
            'scope'       => 'session',   // one per session, tied to a venture
            'source_form' => 'Mentor-Sessions-Reporting-Template',
        ],
        'final_report' => [
            'label'       => 'Final Mentor Report - Per Venture',
            'short'       => 'Final Venture Report',
            'scope'       => 'venture',   // one per venture, end of fellowship
            'source_form' => 'Final-Mentor-Report-Per-Venture',
        ],
        'portfolio_report' => [
            'label'       => 'Final Mentor Report - Strategic Portfolio Reflection',
            'short'       => 'Portfolio Reflection',
            'scope'       => 'mentor',    // one per mentor, covers all their ventures
            'source_form' => 'Final-Mentors-Reporting-Template',
        ],
        'cohort_evaluation' => [
            'label'       => 'Mentor Mid-Cohort Performance Evaluation',
            'short'       => 'Cohort Evaluation',
            'scope'       => 'venture',   // one per venture (can be bulk-generated for all)
            'source_form' => 'Mentors-Cohort-Performance-Evaluation-Reporting-Template',
        ],
    ];
}

function rs_scale_1to5(): array
{
    return ['1', '2', '3', '4', '5'];
}

/** Shared "evidence attachment log" block used by several templates. */
function rs_evidence_field(string $key = 'evidence_log', array $extraCols = []): array
{
    $cols = [
        ['key' => 'description', 'label' => 'Description', 'type' => 'text'],
        ['key' => 'file_ref',    'label' => 'File Name / Reference', 'type' => 'text'],
        ['key' => 'type',        'label' => 'Type', 'type' => 'choice', 'options' => ['PDF', 'Photo', 'Link', 'Other']],
    ];
    foreach ($extraCols as $c) $cols[] = $c;

    return [
        'key'     => $key,
        'label'   => 'Evidence Attachment Log',
        'type'    => 'dynamic_table',
        'columns' => $cols,
    ];
}

function rs_get_schema(string $type): array
{
    switch ($type) {

        case 'session_report':
            return [
                'meta' => ['label' => 'Mentorship Session Report'],
                'sections' => [
                    [
                        'title' => '1. Mentor & Session Details',
                        'fields' => [
                            ['key' => 'founders_present', 'label' => 'Founder(s) / Representative(s) Present', 'type' => 'text'],
                            ['key' => 'session_date', 'label' => 'Session Date', 'type' => 'date'],
                            ['key' => 'session_duration', 'label' => 'Session Duration', 'type' => 'text', 'placeholder' => 'e.g. 60 minutes'],
                            ['key' => 'mode', 'label' => 'Mode of Engagement', 'type' => 'choice', 'options' => ['Virtual', 'Physical', 'Phone', 'Other']],
                            ['key' => 'theme', 'label' => 'Session Theme / Focus Area', 'type' => 'multi_choice', 'options' => [
                                'Product Development', 'Business Model', 'Market Access', 'Finance & Investment Readiness',
                                'Governance & Leadership', 'People & Team', 'Marketing',
                            ]],
                        ],
                    ],
                    [
                        'title' => '2. Key Discussion Areas & Insights',
                        'fields' => [
                            [
                                'key' => 'discussion_areas', 'label' => 'Discussion Areas', 'type' => 'fixed_rows_table',
                                'row_col_label' => 'Discussion Area',
                                'rows' => [
                                    'business_model' => 'Business Model / Value Proposition',
                                    'product_tech'   => 'Product / Technology Development',
                                    'market'         => 'Customers / Market Traction',
                                    'revenue'        => 'Revenue / Commercialisation',
                                    'team_ops'       => 'Team & Operations',
                                    'finance'        => 'Finance / Investment Readiness',
                                    'other'          => 'Other',
                                ],
                                'columns' => [
                                    ['key' => 'insights', 'label' => 'Key Insights / Observations', 'type' => 'textarea'],
                                    ['key' => 'support',  'label' => 'Mentor Support Provided', 'type' => 'textarea'],
                                ],
                            ],
                        ],
                    ],
                    [
                        'title' => '3. Venture Progress / Milestones',
                        'fields' => [
                            ['key' => 'progress_since_previous', 'label' => 'Progress Since Previous Engagement', 'type' => 'textarea'],
                            [
                                'key' => 'achievements', 'label' => 'Key Achievements / Milestones', 'type' => 'dynamic_table',
                                'columns' => [
                                    ['key' => 'item', 'label' => 'Achievement / Milestone', 'type' => 'text'],
                                    ['key' => 'status', 'label' => 'Status', 'type' => 'choice', 'options' => ['Completed', 'In Progress', 'Delayed']],
                                ],
                            ],
                            [
                                'key' => 'challenges', 'label' => 'Challenges & Constraints Identified', 'type' => 'dynamic_table',
                                'columns' => [
                                    ['key' => 'challenge', 'label' => 'Challenge', 'type' => 'text'],
                                    ['key' => 'impact', 'label' => 'Impact on Venture', 'type' => 'textarea'],
                                    ['key' => 'action', 'label' => 'Recommended Support / Action', 'type' => 'textarea'],
                                ],
                            ],
                        ],
                    ],
                    [
                        'title' => '4. Agreed Actions & Follow-Up',
                        'fields' => [
                            [
                                'key' => 'actions', 'label' => 'Agreed Next Steps', 'type' => 'dynamic_table',
                                'columns' => [
                                    ['key' => 'action', 'label' => 'Action Item', 'type' => 'text'],
                                    ['key' => 'responsible', 'label' => 'Responsible Person', 'type' => 'text'],
                                    ['key' => 'timeline', 'label' => 'Timeline', 'type' => 'text'],
                                ],
                            ],
                            [
                                'key' => 'followups', 'label' => 'Follow-Up Requirements', 'type' => 'dynamic_table',
                                'columns' => [
                                    ['key' => 'requirement', 'label' => 'Requirement', 'type' => 'text'],
                                    ['key' => 'details', 'label' => 'Details', 'type' => 'textarea'],
                                ],
                            ],
                        ],
                    ],
                    [
                        'title' => '5. Mentor Assessment & Observations',
                        'fields' => [
                            [
                                'key' => 'assessment', 'label' => "Venture's Current Position", 'type' => 'fixed_rows_table',
                                'row_col_label' => 'Area',
                                'rows' => [
                                    'business_model_clarity' => 'Business Model Clarity',
                                    'product_readiness'      => 'Product Readiness',
                                    'market_validation'      => 'Market Validation',
                                    'team_execution'         => 'Team Execution Capacity',
                                    'investment_readiness'   => 'Investment Readiness',
                                ],
                                'columns' => [
                                    ['key' => 'level', 'label' => 'Assessment', 'type' => 'choice', 'options' => ['Needs Support', 'Developing', 'Strong']],
                                ],
                            ],
                            ['key' => 'key_observations', 'label' => 'Key Mentor Observations', 'type' => 'textarea'],
                        ],
                    ],
                    [
                        'title' => '6. Risks & Escalations',
                        'fields' => [
                            [
                                'key' => 'risks', 'label' => 'Risks / Concerns', 'type' => 'dynamic_table',
                                'columns' => [
                                    ['key' => 'risk', 'label' => 'Risk / Concern', 'type' => 'text'],
                                    ['key' => 'severity', 'label' => 'Severity', 'type' => 'choice', 'options' => ['H', 'M', 'L']],
                                    ['key' => 'action', 'label' => 'Recommended Action', 'type' => 'textarea'],
                                ],
                            ],
                            ['key' => 'escalation_required', 'label' => 'Escalation Required?', 'type' => 'choice', 'options' => ['Yes - notify Programme Team', 'No - continue monitoring']],
                        ],
                    ],
                    [
                        'title' => 'Annex - Evidence & Supporting Documentation',
                        'fields' => [rs_evidence_field('evidence_log')],
                    ],
                ],
            ];

        case 'final_report':
            return [
                'meta' => ['label' => 'Final Mentor Report - Per Venture'],
                'sections' => [
                    [
                        'title' => 'A. Report Details',
                        'fields' => [
                            ['key' => 'business_analyst', 'label' => 'Mentor Details', 'type' => 'text'],
                            ['key' => 'fellowship_period', 'label' => 'Fellowship Period', 'type' => 'text', 'default' => 'Month 1 – Month 6'],
                            ['key' => 'date_of_report', 'label' => 'Date of Report', 'type' => 'date'],
                        ],
                    ],
                    [
                        'title' => 'B. Venture Profile & Engagement Overview',
                        'fields' => [
                            ['key' => 'sector', 'label' => 'Sector / Sub-sector', 'type' => 'text'],
                            ['key' => 'stage_at_intake', 'label' => 'Stage at Intake', 'type' => 'choice', 'options' => ['Pre-revenue', 'Early-revenue', 'Growth']],
                            ['key' => 'stage_at_exit', 'label' => 'Stage at Exit', 'type' => 'choice', 'options' => ['Pre-revenue', 'Early-revenue', 'Growth', 'Scale']],
                            ['key' => 'sessions_individual', 'label' => 'Total Sessions - Individual', 'type' => 'text'],
                            ['key' => 'sessions_cohort_group', 'label' => 'Total Sessions - Cohort Group', 'type' => 'text'],
                            ['key' => 'engagement_summary', 'label' => 'Engagement Summary', 'type' => 'textarea'],
                        ],
                    ],
                    [
                        'title' => 'C. Milestone Achievement Summary',
                        'fields' => [
                            [
                                'key' => 'milestones', 'label' => 'Milestones (per Growth Action Plan)', 'type' => 'dynamic_table',
                                'columns' => [
                                    ['key' => 'milestone', 'label' => 'Milestone', 'type' => 'text'],
                                    ['key' => 'target_date', 'label' => 'Target Date', 'type' => 'date'],
                                    ['key' => 'status', 'label' => 'Status', 'type' => 'choice', 'options' => ['Met', 'Partial', 'Missed']],
                                    ['key' => 'notes', 'label' => 'Notes', 'type' => 'text'],
                                ],
                            ],
                        ],
                    ],
                    [
                        'title' => 'D. Final Performance Scorecard',
                        'fields' => [
                            [
                                'key' => 'scorecard', 'label' => 'Final Performance Scorecard', 'type' => 'scale_table',
                                'show_total' => true, 'max_total' => 50,
                                'rows' => [
                                    'business_model'   => 'Business Model Viability',
                                    'revenue_financial' => 'Revenue & Financial Performance',
                                    'product_maturity' => 'Product / Service Maturity',
                                    'market_traction'  => 'Market Traction',
                                    'team_strength'    => 'Team Strength & Organisational Health',
                                    'responsiveness'   => 'Responsiveness to Mentorship',
                                    'governance'       => 'Governance & Compliance',
                                    'impact'           => 'Impact & EdTech Contribution',
                                    'investor_ready'   => 'Investor / Funder Readiness',
                                    'growth'           => 'Growth Trajectory & Scalability',
                                ],
                            ],
                            ['key' => 'overall_rating', 'label' => 'Overall Rating', 'type' => 'choice', 'options' => ['Unsatisfactory', 'Developing', 'Performing', 'Excelling']],
                        ],
                    ],
                    [
                        'title' => 'E. Narrative Assessment',
                        'fields' => [
                            ['key' => 'key_achievements', 'label' => 'Key Achievements (3–5 most significant)', 'type' => 'textarea'],
                            ['key' => 'challenges_encountered', 'label' => 'Challenges Encountered', 'type' => 'textarea'],
                            ['key' => 'mentorship_impact', 'label' => 'Mentorship Impact', 'type' => 'textarea'],
                            ['key' => 'unresolved_issues', 'label' => 'Unresolved Issues', 'type' => 'textarea'],
                            ['key' => 'founder_assessment', 'label' => "Founder Assessment", 'type' => 'textarea'],
                            ['key' => 'post_fellowship_outlook', 'label' => 'Post-Fellowship Outlook', 'type' => 'textarea'],
                        ],
                    ],
                    [
                        'title' => 'F. Deliverables Completion Review',
                        'fields' => [
                            [
                                'key' => 'deliverables', 'label' => 'Deliverables', 'type' => 'fixed_rows_table',
                                'row_col_label' => 'Deliverable',
                                'rows' => [
                                    'gap'          => 'Growth Action Plan - developed and maintained',
                                    'vdr'          => 'Virtual data room - fully populated',
                                    'pitch_deck'   => 'Pitch deck reviewed and polished',
                                    'deal_book'    => 'Deal book completed',
                                    'mid_eval'     => 'Mid-cohort evaluation submitted',
                                    'advisory'     => 'All monthly advisory summaries submitted',
                                    'pitching'     => 'Venture coached on pitching',
                                    'risk_flags'   => 'Risk flags raised where applicable',
                                    'peer_sessions'=> 'Peer learning sessions facilitated',
                                ],
                                'columns' => [
                                    ['key' => 'completed', 'label' => 'Completed (Y/N)', 'type' => 'choice', 'options' => ['Y', 'N']],
                                    ['key' => 'quality', 'label' => 'Quality (1–5)', 'type' => 'choice', 'options' => rs_scale_1to5()],
                                    ['key' => 'notes', 'label' => 'Notes', 'type' => 'text'],
                                ],
                            ],
                        ],
                    ],
                    [
                        'title' => 'G. Final Recommendations',
                        'fields' => [
                            ['key' => 'post_fellowship_support', 'label' => 'Post-Fellowship Support Needed', 'type' => 'multi_choice', 'options' => ['Investor introductions', 'Technical assistance', 'Market access support', 'Legal/compliance', 'None required']],
                            ['key' => 'alumni_recommendation', 'label' => 'Alumni Network Recommendation', 'type' => 'choice', 'options' => ['Strong recommend', 'Recommend', 'Recommend with reservations']],
                            ['key' => 'completion_status', 'label' => 'Overall Fellowship Completion Status', 'type' => 'choice', 'options' => ['Successfully completed', 'Completed with conditions', 'Did not meet requirements']],
                            ['key' => 'additional_recommendations', 'label' => 'Additional Recommendations', 'type' => 'textarea'],
                        ],
                    ],
                    [
                        'title' => 'H. Evidence Attachment Log',
                        'fields' => [rs_evidence_field('evidence_log', [
                            ['key' => 'related_section', 'label' => 'Related Section', 'type' => 'text'],
                        ])],
                    ],
                ],
            ];

        case 'portfolio_report':
            return [
                'meta' => ['label' => 'Final Mentor Report - Strategic Portfolio Reflection'],
                'sections' => [
                    [
                        'title' => 'A. Mentor & Submission Details',
                        'fields' => [
                            ['key' => 'cohort_cycle', 'label' => 'Cohort Name / Cycle', 'type' => 'text'],
                            ['key' => 'num_ventures_supported', 'label' => 'Number of Ventures Supported', 'type' => 'text'],
                            ['key' => 'fellowship_period', 'label' => 'Fellowship Period', 'type' => 'text', 'default' => 'Month 1 – Month 6'],
                            ['key' => 'date_of_report', 'label' => 'Date of Report Submission', 'type' => 'date'],
                        ],
                    ],
                    [
                        'title' => '1.0 Strategic Portfolio Assessment',
                        'fields' => [
                            ['key' => 'overall_trajectory', 'label' => '1.1 Overall Cohort Trajectory', 'type' => 'textarea'],
                            ['key' => 'cross_cutting_strengths', 'label' => '1.2 Cross-Cutting Strengths', 'type' => 'textarea'],
                            ['key' => 'cross_cutting_weaknesses', 'label' => '1.3 Cross-Cutting Weaknesses', 'type' => 'textarea'],
                            ['key' => 'pivot_moment', 'label' => '1.4 Most Significant Pivot or Growth Moment', 'type' => 'textarea'],
                            ['key' => 'ventures_requiring_attention', 'label' => '1.5 Ventures Requiring Post-Fellowship Attention', 'type' => 'textarea'],
                        ],
                    ],
                    [
                        'title' => '2.0 Venture Portfolio Progress Summary',
                        'fields' => [
                            [
                                'key' => 'venture_summary', 'label' => 'Ventures Covered This Period', 'type' => 'dynamic_table',
                                'columns' => [
                                    ['key' => 'venture_id', 'label' => 'Venture', 'type' => 'venture_select'],
                                    ['key' => 'sector', 'label' => 'Sector/Focus', 'type' => 'text'],
                                    ['key' => 'irs_start', 'label' => 'IRS Start (1–5)', 'type' => 'text'],
                                    ['key' => 'irs_now', 'label' => 'IRS Now (1–5)', 'type' => 'text'],
                                    ['key' => 'growth_area', 'label' => 'Primary Growth Area', 'type' => 'text'],
                                    ['key' => 'key_shift', 'label' => 'Key Shift (Start ? Now)', 'type' => 'textarea'],
                                ],
                            ],
                            ['key' => 'cohort_trajectory_narrative', 'label' => '2.3 Cohort Trajectory Narrative', 'type' => 'textarea'],
                        ],
                    ],
                    [
                        'title' => '3.0 Ecosystem & Sector Observations',
                        'fields' => [
                            ['key' => 'state_of_edtech', 'label' => '3.1 State of EdTech in Uganda', 'type' => 'textarea'],
                            ['key' => 'market_barriers', 'label' => '3.2 Market & Structural Barriers', 'type' => 'textarea'],
                            ['key' => 'investment_readiness_cohort', 'label' => '3.3 Investment Readiness of the Cohort', 'type' => 'textarea'],
                            ['key' => 'equity_inclusion', 'label' => '3.4 Equity, Inclusion & Safeguarding', 'type' => 'textarea'],
                        ],
                    ],
                    [
                        'title' => '4.0 Mentorship Reflection & Learnings',
                        'fields' => [
                            ['key' => 'what_worked', 'label' => '4.1 What Worked Well', 'type' => 'textarea'],
                            ['key' => 'what_differently', 'label' => '4.2 What You Would Do Differently', 'type' => 'textarea'],
                            ['key' => 'hardest_challenge', 'label' => '4.3 Hardest Mentoring Challenge', 'type' => 'textarea'],
                            ['key' => 'key_learning', 'label' => '4.4 Key Personal Learning', 'type' => 'textarea'],
                        ],
                    ],
                    [
                        'title' => '5.0 Programme Feedback',
                        'fields' => [
                            ['key' => 'structure_design', 'label' => '5.1 Programme Structure & Design', 'type' => 'textarea'],
                            ['key' => 'onboarding_clarity', 'label' => '5.2 Mentor Onboarding & Clarity', 'type' => 'textarea'],
                            ['key' => 'ba_collaboration', 'label' => '5.3 Collaboration with Business Analysts', 'type' => 'textarea'],
                            ['key' => 'tools_resources', 'label' => '5.4 Tools, Templates & Resources', 'type' => 'textarea'],
                            ['key' => 'top_recommendation', 'label' => '5.5 Top Recommendation for Next Cohort', 'type' => 'textarea'],
                        ],
                    ],
                    [
                        'title' => '6.0 Final Strategic Recommendations',
                        'fields' => [
                            ['key' => 'alumni_ready', 'label' => '6.1 Ventures Ready for Alumni Network', 'type' => 'textarea'],
                            ['key' => 'followon_funding', 'label' => '6.2 Ventures Recommended for Follow-on Funding', 'type' => 'textarea'],
                            ['key' => 'continued_monitoring', 'label' => '6.3 Ventures Requiring Continued Monitoring', 'type' => 'textarea'],
                            ['key' => 'ecosystem_recommendations', 'label' => '6.4 Ecosystem-Level Recommendations', 'type' => 'textarea'],
                        ],
                    ],
                    [
                        'title' => '7.0 / 8.0 Final Ratings',
                        'fields' => [
                            ['key' => 'overall_cohort_assessment', 'label' => '7.0 Overall Cohort Assessment', 'type' => 'choice', 'options' => [
                                'Strong cohort - majority met or exceeded expectations',
                                'Mixed cohort - performance varied significantly',
                                'Cohort required more intensive support than anticipated',
                            ]],
                            ['key' => 'willingness_to_reengage', 'label' => "8.0 Mentor's Willingness to Re-engage", 'type' => 'choice', 'options' => [
                                'Yes - same structure', 'Yes - with modifications', 'No',
                            ]],
                        ],
                    ],
                    [
                        'title' => 'Annex - Evidence Attachment Log (cohort-level, max 6)',
                        'fields' => [rs_evidence_field('evidence_log', [
                            ['key' => 'notes', 'label' => 'Notes', 'type' => 'text'],
                        ])],
                    ],
                ],
            ];

        case 'cohort_evaluation':
            return [
                'meta' => ['label' => 'Mentor Mid-Cohort Performance Evaluation'],
                'sections' => [
                    [
                        'title' => 'A. Evaluator & Submission Details',
                        'fields' => [
                            ['key' => 'cohort_cycle', 'label' => 'Cohort Name / Cycle', 'type' => 'text'],
                            ['key' => 'evaluation_period', 'label' => 'Evaluation Period', 'type' => 'text', 'default' => 'Mid-Cohort (Months 1–3)'],
                            ['key' => 'date_of_submission', 'label' => 'Date of Submission', 'type' => 'date'],
                            ['key' => 'assigned_business_analyst', 'label' => 'Assigned Mentor', 'type' => 'text'],
                        ],
                    ],
                    [
                        'title' => 'B1. Performance Scorecard',
                        'fields' => [
                            [
                                'key' => 'scorecard', 'label' => 'Performance Scorecard', 'type' => 'scale_table',
                                'show_total' => true, 'max_total' => 50,
                                'rows' => [
                                    'business_model'  => 'Business Model Clarity',
                                    'revenue_gen'     => 'Revenue Generation',
                                    'product_dev'     => 'Product / Service Development',
                                    'team_capacity'   => 'Team Capacity & Execution',
                                    'financial_mgmt'  => 'Financial Management',
                                    'market_traction' => 'Market Traction & Customer Engagement',
                                    'responsiveness'  => 'Responsiveness to Mentorship',
                                    'milestones'      => 'Milestone Achievement',
                                    'governance'      => 'Governance & Compliance',
                                    'impact_sdg'      => 'Impact & SDG Alignment',
                                ],
                            ],
                            ['key' => 'overall_rating', 'label' => 'Overall Rating', 'type' => 'choice', 'options' => ['Unsatisfactory', 'Developing', 'Performing', 'Excelling']],
                        ],
                    ],
                    [
                        'title' => 'B2. Qualitative Narrative Assessment',
                        'fields' => [
                            ['key' => 'strengths_observed', 'label' => 'Strengths Observed', 'type' => 'textarea'],
                            ['key' => 'areas_improvement', 'label' => 'Areas Requiring Improvement', 'type' => 'textarea'],
                            ['key' => 'milestones_achieved', 'label' => 'Key Milestones Achieved', 'type' => 'textarea'],
                            ['key' => 'milestones_not_met', 'label' => 'Milestones Not Yet Met', 'type' => 'textarea'],
                            ['key' => 'support_provided', 'label' => 'Specific Support Provided by Mentor', 'type' => 'textarea'],
                            ['key' => 'recommended_focus', 'label' => 'Recommended Focus for Second Half', 'type' => 'textarea'],
                        ],
                    ],
                    [
                        'title' => 'B3. Risk & Concern Register',
                        'fields' => [
                            [
                                'key' => 'risks', 'label' => 'Risks / Concerns', 'type' => 'dynamic_table',
                                'columns' => [
                                    ['key' => 'risk', 'label' => 'Risk / Concern', 'type' => 'text'],
                                    ['key' => 'severity', 'label' => 'Severity', 'type' => 'choice', 'options' => ['H', 'M', 'L']],
                                    ['key' => 'impact', 'label' => 'Impact if Unaddressed', 'type' => 'textarea'],
                                    ['key' => 'action', 'label' => 'Recommended Action', 'type' => 'textarea'],
                                ],
                            ],
                            ['key' => 'escalation_required', 'label' => 'Escalation Required?', 'type' => 'choice', 'options' => ['Yes - notify Programme Manager immediately', 'No - monitor and report next month']],
                        ],
                    ],
                    [
                        'title' => 'B4. Stage Progression & Grant Disbursement Recommendation',
                        'fields' => [
                            ['key' => 'stage_progression', 'label' => 'Stage Progression Recommendation', 'type' => 'choice', 'options' => ['Progress to next stage', 'Conditional progression (with remediation plan)', 'Hold - requires review panel']],
                            ['key' => 'grant_disbursement', 'label' => 'Grant Disbursement Recommendation', 'type' => 'choice', 'options' => ['Recommend disbursement', 'Withhold pending milestone completion', 'Partial disbursement']],
                            ['key' => 'justification', 'label' => 'Justification for Recommendation', 'type' => 'textarea'],
                        ],
                    ],
                    [
                        'title' => 'C. Cohort-Wide Reflections',
                        'fields' => [
                            ['key' => 'general_strengths', 'label' => 'General Cohort Strengths', 'type' => 'textarea'],
                            ['key' => 'common_challenges', 'label' => 'Common Challenges', 'type' => 'textarea'],
                            ['key' => 'support_gaps', 'label' => 'Programme Support Gaps', 'type' => 'textarea'],
                            ['key' => 'recommendations_to_team', 'label' => 'Recommendations to Programme Team', 'type' => 'textarea'],
                        ],
                    ],
                    [
                        'title' => 'D. Evidence Attachment Log',
                        'fields' => [rs_evidence_field('evidence_log', [
                            ['key' => 'startup_referenced', 'label' => 'Venture Referenced', 'type' => 'text'],
                        ])],
                    ],
                ],
            ];
    }

    return ['meta' => ['label' => 'Unknown report'], 'sections' => []];
}